<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Subscriber;

/**
 * The self-service opt-out link, sent when someone uses the footer's
 * "Unsubscribe" button.
 *
 * The mirror of ConfirmMailingList on the way out: the footer never removes an
 * address on sight, it emails that address its own signed unsubscribe link, so
 * leaving the list — like joining it — is proven from the inbox rather than
 * from a form a stranger could post (§9). One click on the page the link opens
 * is still one deliberate click.
 *
 * Owed to the recipient: asking to leave is a request we always honour, so this
 * is not withheld the way a marketing confirmation is from a spam complainer.
 */
final class UnsubscribeLink extends LoggedMail
{
    public function __construct(public readonly Subscriber $subscriber) {}

    public static function emailKey(): string
    {
        return 'marketing.list_unsubscribe';
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
        return 'Confirm you want to unsubscribe';
    }

    protected function markdownView(): string
    {
        return 'emails.marketing.unsubscribe-link';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [
            'subscriber' => $this->subscriber,
            // Signed freshly on every send, which is what makes this safe to
            // resend: the link is regenerated, never replayed from a stored body.
            'unsubscribeUrl' => $this->subscriber->unsubscribeUrl(),
        ];
    }
}
