<?php

declare(strict_types=1);

namespace App\Models;

use App\Email\EmailCatalogue;
use App\Email\EmailDefinition;
use App\Enums\EmailCategory;
use App\Enums\EmailStatus;
use Database\Factories\EmailSendFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to send one email.
 *
 * Written before anything reaches a mail server, so a send that fails or is
 * refused still leaves a record — see the migration for why that is the whole
 * point rather than a nicety.
 *
 * @property EmailStatus $status
 * @property EmailCategory $category
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $body_pruned_at
 * @property array<string, mixed>|null $payload
 */
class EmailSend extends Model
{
    /** @use HasFactory<EmailSendFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid', 'email_key', 'category', 'mailable', 'payload',
        'to_email', 'to_name', 'cc', 'bcc', 'reply_to',
        'subject', 'body_html', 'body_pruned_at',
        'user_id', 'subscriber_id', 'related_type', 'related_id',
        'status', 'mailer', 'message_id',
        'queued_at', 'sent_at', 'failed_at', 'error', 'attempts',
        'outcome_note', 'resent_from_id', 'resent_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'category' => EmailCategory::class,
            'payload' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'body_pruned_at' => 'datetime',
        ];
    }

    // What this is -----------------------------------------------------------------

    /**
     * The catalogue entry behind this row, if it still exists.
     *
     * Null is a real answer: `email_key` is a plain string precisely so a row
     * outlives an email being retired, and the screen falls back to the stored
     * key rather than hiding the row.
     */
    public function definition(): ?EmailDefinition
    {
        return EmailCatalogue::find($this->email_key);
    }

    public function name(): string
    {
        $definition = $this->definition();

        return $definition === null ? $this->email_key : $definition->name;
    }

    /** Who it was addressed to, named where we know the name. */
    public function recipient(): string
    {
        $name = $this->to_name;

        return $name === null || trim($name) === ''
            ? $this->to_email
            : "{$name} <{$this->to_email}>";
    }

    // The body -----------------------------------------------------------------

    public function hasBody(): bool
    {
        return $this->body_html !== null && $this->body_html !== '';
    }

    /**
     * Why the preview is empty, in words.
     *
     * "We never stored it" and "we no longer keep it" are different facts, and a
     * screen showing a blank frame for both teaches people the screen is broken.
     */
    public function bodyAbsenceReason(): ?string
    {
        if ($this->hasBody()) {
            return null;
        }

        return $this->body_pruned_at !== null
            ? 'The content of this email is no longer stored. Sent '.($this->created_at?->diffForHumans() ?? 'some time ago').', and bodies of this kind are kept for a limited period.'
            : 'No content was stored for this send.';
    }

    /**
     * Whether "resend exactly as it was" makes sense.
     *
     * Refused where the body holds a signed, expiring link: §9 puts every file
     * behind one, so replaying a body stored in March sends a customer a page
     * that stopped working in March. Rebuilding is the honest option there, and
     * the screen says so rather than offering a button that produces a dead link.
     */
    public function mayResendVerbatim(): bool
    {
        $definition = $this->definition();

        return $this->hasBody()
            && $definition !== null
            && $definition->isResendable()
            && ! $definition->hasExpiringLinks;
    }

    /** Whether the email can be built again from scratch. */
    public function mayResendRebuilt(): bool
    {
        $definition = $this->definition();

        return $definition !== null && $definition->isBuilt() && $definition->isResendable();
    }

    /** Why neither resend mode is offered, for the screen to say out loud. */
    public function resendRefusal(): ?string
    {
        if ($this->mayResendVerbatim() || $this->mayResendRebuilt()) {
            return null;
        }

        $definition = $this->definition();

        if ($definition === null) {
            return 'This email is no longer part of the system, so it cannot be rebuilt.';
        }

        return $definition->notResendableReason
            ?? ($definition->isBuilt()
                ? 'There is nothing stored to resend and no way to rebuild it.'
                : 'This email has no trigger behind it yet.');
    }

    // State -----------------------------------------------------------------

    public function markSending(): void
    {
        $this->forceFill([
            'status' => EmailStatus::Sending,
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    public function markSent(?string $messageId, ?string $mailer): void
    {
        $this->forceFill([
            'status' => EmailStatus::Sent,
            'sent_at' => now(),
            'message_id' => $messageId,
            'mailer' => $mailer,
            'error' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => EmailStatus::Failed,
            'failed_at' => now(),
            // Bounded: a stack trace from a mail transport can run to kilobytes
            // and the useful part is always at the front.
            'error' => mb_substr($error, 0, 2000),
        ])->save();
    }

    public function markDeferred(string $note): void
    {
        $this->forceFill([
            'status' => EmailStatus::Deferred,
            'outcome_note' => $note,
        ])->save();
    }

    public function markSuppressed(string $note): void
    {
        $this->forceFill([
            'status' => EmailStatus::Suppressed,
            'outcome_note' => $note,
        ])->save();
    }

    // Relationships -----------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /** @return MorphTo<Model, $this> */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<EmailSend, $this> */
    public function resentFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resent_from_id');
    }

    /** @return HasMany<EmailSend, $this> */
    public function resends(): HasMany
    {
        return $this->hasMany(self::class, 'resent_from_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resent_by_id');
    }
}
