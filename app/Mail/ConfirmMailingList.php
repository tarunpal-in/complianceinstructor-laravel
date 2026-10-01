<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Subscriber;

/**
 * The double opt-in confirmation.
 *
 * The email that unblocks a setting already on the screen. `mailing_list.
 * double_opt_in` ships off, with help text reading "turn this on once
 * transactional mail is wired up — until then a signup would wait forever for an
 * email nothing can send". This is that email.
 *
 * Required although it is marketing, and the catalogue says why: with double
 * opt-in on and this switched off, every signup waits forever. The double opt-in
 * setting is the switch; a second one here would only be a way to break the
 * first. For the same reason it carries no unsubscribe link — they are not on the
 * list yet, and offering them a way off a list they have not joined is a dead end.
 */
final class ConfirmMailingList extends LoggedMail
{
    public function __construct(public readonly Subscriber $subscriber) {}

    public static function emailKey(): string
    {
        return 'marketing.list_confirm';
    }

    /** @param  array<string, mixed>  $payload */
    public static function rebuild(array $payload): ?static
    {
        $subscriber = Subscriber::query()->find($payload['subscriber_id'] ?? null);

        return $subscriber === null ? null : new self($subscriber);
    }

    public function recipientEmail(): string
    {
        return $this->subscriber->email;
    }

    /** Nullable, unlike the others: a footer signup box asks only for an address. */
    public function recipientName(): ?string
    {
        return $this->subscriber->name;
    }

    public function recipientSubscriber(): Subscriber
    {
        return $this->subscriber;
    }

    public function relatedRecord(): Subscriber
    {
        return $this->subscriber;
    }

    /** @return array<string, mixed> */
    public function logPayload(): array
    {
        return ['subscriber_id' => $this->subscriber->getKey()];
    }

    protected function subjectLine(): string
    {
        return 'Please confirm your email address';
    }

    protected function markdownView(): string
    {
        return 'emails.marketing.confirm-list';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [
            'subscriber' => $this->subscriber,
            // Freshly signed on every send, which is what makes this one of the
            // few emails safe to resend: the link is regenerated rather than
            // replayed from a stored body.
            'confirmUrl' => $this->subscriber->confirmUrl(),
        ];
    }
}
