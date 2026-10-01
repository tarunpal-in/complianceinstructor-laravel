<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SuppressionReason;
use Database\Factories\EmailSuppressionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One address held back.
 *
 * @property SuppressionReason $reason
 * @property Carbon|null $lifted_at
 */
class EmailSuppression extends Model
{
    /** @use HasFactory<EmailSuppressionFactory> */
    use HasFactory;

    protected $fillable = [
        'email', 'reason', 'source', 'note', 'email_send_id',
        'created_by_id', 'lifted_at', 'lifted_by_id', 'lifted_reason',
    ];

    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * Whether this address should be held back from an email of this kind.
     *
     * The second argument is the point. A bounced mailbox blocks everything —
     * sending an invoice to an address that does not exist achieves nothing and
     * costs sending reputation. A spam complaint blocks marketing only: they
     * complained about a newsletter, which is not us deciding they may not have
     * the receipt for what they paid for.
     */
    public static function blocks(string $email, bool $isRequired): bool
    {
        $suppression = self::activeFor($email);

        if ($suppression === null) {
            return false;
        }

        return $isRequired
            ? $suppression->reason->blocksRequiredMail()
            : true;
    }

    public static function activeFor(string $email): ?self
    {
        return self::query()
            ->where('email', Str::lower(trim($email)))
            ->whereNull('lifted_at')
            ->first();
    }

    /**
     * Record an address as not worth sending to.
     *
     * Updates rather than inserts a second row — the email is UNIQUE, and a
     * repeat bounce is the same fact arriving twice. Lifting is cleared on the
     * way through, because an address that has started bouncing again is
     * suppressed again whatever happened before.
     */
    public static function record(
        string $email,
        SuppressionReason $reason,
        ?string $source = null,
        ?string $note = null,
        ?int $sendId = null,
        ?User $by = null,
    ): self {
        $suppression = self::query()->firstOrNew(['email' => Str::lower(trim($email))]);

        $suppression->forceFill([
            'reason' => $reason,
            'source' => $source,
            'note' => $note,
            'email_send_id' => $sendId,
            'created_by_id' => $by?->getKey(),
            'lifted_at' => null,
            'lifted_by_id' => null,
            'lifted_reason' => null,
        ])->save();

        return $suppression;
    }

    public function lift(?User $by, ?string $reason = null): void
    {
        $this->forceFill([
            'lifted_at' => now(),
            'lifted_by_id' => $by?->getKey(),
            'lifted_reason' => $reason,
        ])->save();
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }

    /** @return BelongsTo<EmailSend, $this> */
    public function send(): BelongsTo
    {
        return $this->belongsTo(EmailSend::class, 'email_send_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
