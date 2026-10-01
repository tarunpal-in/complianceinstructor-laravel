<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EmailSend;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;

/**
 * An email replayed exactly as it was first sent.
 *
 * The verbatim half of the resend button. It carries the stored subject and body
 * rather than a template, so an invoice resent two months later is the invoice
 * that was sent — §5.4's frozen-snapshot rule, applied to what a customer was
 * told rather than to what they were charged.
 *
 * It keeps the original's catalogue key deliberately, so a resent receipt still
 * counts as a receipt: its category decides its queue and its retention, and the
 * Emails screen's 30-day figure should include it. A resend is the same email
 * going out again, not a different kind of email.
 *
 * Never used for anything holding a signed, expiring link — EmailSend::
 * mayResendVerbatim refuses those, because replaying a dead link while reporting
 * success is worse than refusing to replay it.
 */
final class StoredEmail extends LoggedMail
{
    public function __construct(public readonly EmailSend $original) {}

    /**
     * The original's key, not one of its own.
     *
     * Overridden as an instance concern rather than a static one, which the base
     * class allows for exactly this case: this class is the only email whose
     * identity is decided per instance.
     */
    public static function emailKey(): string
    {
        // Only reached if something constructs this without an original, which
        // the constructor makes impossible. The instance method below is what
        // the Dispatcher actually consults.
        return 'account.password_changed';
    }

    public function definition(): \App\Email\EmailDefinition
    {
        $definition = \App\Email\EmailCatalogue::find($this->original->email_key);

        // A retired email can still be replayed — the body is stored, and
        // refusing because the catalogue has moved on would be the log losing
        // the one thing it was keeping.
        return $definition ?? parent::definition();
    }

    /** Never rebuilt. It *is* the stored copy; rebuilding is the other mode. */
    public static function rebuild(array $payload): ?static
    {
        return null;
    }

    public function recipientEmail(): string
    {
        return $this->original->to_email;
    }

    public function recipientName(): ?string
    {
        return $this->original->to_name;
    }

    public function recipientUser(): ?User
    {
        return $this->original->user;
    }

    public function recipientSubscriber(): ?Subscriber
    {
        return $this->original->subscriber;
    }

    public function relatedRecord(): ?Model
    {
        return $this->original->related;
    }

    /** @return array<string, mixed> */
    public function logPayload(): array
    {
        // The original's payload, so a resend of a resend can still be rebuilt.
        return $this->original->payload ?? [];
    }

    protected function subjectLine(): string
    {
        return $this->original->subject;
    }

    /** Unused — content() is overridden to serve the stored HTML directly. */
    protected function markdownView(): string
    {
        return 'emails.partials.footer';
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->original->body_html ?? '');
    }
}
