<?php

declare(strict_types=1);

namespace App\Mail;

use App\Email\EmailCatalogue;
use App\Email\EmailDefinition;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Every email in the application extends this.
 *
 * Three things are centralised here because getting them wrong once is a
 * customer-visible mistake and getting them wrong thirty-four times is a project:
 *
 * **Markdown, not hand-written HTML.** Laravel's markdown mailables produce a
 * plain-text alternative for free, and the client sends through a consumer Gmail
 * account — a free-domain sender with no DKIM for its own domain, mailing HR
 * departments behind Mimecast and Proofpoint. A text alternative and a light
 * template are two of the few deliverability levers available without paying for
 * a provider.
 *
 * **The from address comes from settings**, so the client can change it without
 * a deploy, and falls back to config. It must stay a mailbox somebody reads:
 * under SMTP a bounce comes back as an email to the From address, and a
 * `noreply@` throws away the only bounce signal that exists here.
 *
 * **Each subclass declares how to rebuild itself** from a small payload of ids.
 * That is what makes the resend button able to offer anything better than
 * replaying stored HTML — which §9's signed expiring URLs make wrong for most of
 * these, because a body stored in March holds a link that died in March.
 */
abstract class LoggedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** The EmailCatalogue key. Must exist there — EmailCatalogueTest proves it. */
    abstract public static function emailKey(): string;

    /**
     * Build this email again from the ids the log kept.
     *
     * Null when the record it described has gone. A resend of a certificate
     * email for a deleted certificate is not an error to shout about; it is a
     * button that should explain itself and do nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract public static function rebuild(array $payload): ?static;

    abstract protected function subjectLine(): string;

    /**
     * The markdown view, e.g. "emails.account.reset-password".
     *
     * Not `view()`: Mailable already has one, with a different signature and a
     * different job (setting the view on an instance rather than declaring it).
     */
    abstract protected function markdownView(): string;

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [];
    }

    /**
     * What the log needs to rebuild this later.
     *
     * Ids, never objects: this is stored as JSON and read back weeks afterwards.
     *
     * @return array<string, mixed>
     */
    public function logPayload(): array
    {
        return [];
    }

    abstract public function recipientEmail(): string;

    public function recipientName(): ?string
    {
        return null;
    }

    /** The account this concerns, where there is one. */
    public function recipientUser(): ?User
    {
        return null;
    }

    /** The mailing-list row this concerns, where there is one. */
    public function recipientSubscriber(): ?Subscriber
    {
        return null;
    }

    /**
     * What this email is about — an order, a refund, a certificate.
     *
     * Drives the "Record" column, and the per-record panels that answer "did
     * this customer get their receipt?" from the order rather than from a list
     * of every email ever sent.
     */
    public function relatedRecord(): ?Model
    {
        return null;
    }

    public function definition(): EmailDefinition
    {
        $definition = EmailCatalogue::find(static::emailKey());

        if ($definition === null) {
            // Not a runtime concern so much as a build one: an email that is not
            // in the catalogue has no toggle, no category, no retention and no
            // row on the Emails screen. Failing loudly here is the only way that
            // stays true.
            throw new RuntimeException(
                'Email ['.static::emailKey().'] is sent in code but not declared in EmailCatalogue, '
                .'so it has no toggle, no category and no row on the Emails screen.'
            );
        }

        return $definition;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: self::sender(),
            replyTo: array_filter([self::replyToAddress()]),
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: $this->markdownView(),
            with: [
                ...$this->viewData(),
                // Available to every template, so a footer can be shared without
                // each email remembering to pass it.
                'emailDefinition' => $this->definition(),
            ],
        );
    }

    /** Who it comes from. Settings first, config as the fallback. */
    public static function sender(): Address
    {
        $address = Setting::declared('email.from_address');
        $name = Setting::declared('email.from_name');

        return new Address(
            is_string($address) && trim($address) !== ''
                ? $address
                : (string) config('mail.from.address'),
            is_string($name) && trim($name) !== ''
                ? $name
                : (string) config('mail.from.name'),
        );
    }

    public static function replyToAddress(): ?Address
    {
        $address = Setting::declared('email.reply_to');

        return is_string($address) && trim($address) !== '' ? new Address($address) : null;
    }
}
