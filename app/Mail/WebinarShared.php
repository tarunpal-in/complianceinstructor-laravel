<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Webinar;

/**
 * Somebody shared a webinar with a colleague — the "Share Webinar" form on the
 * detail page (§7 engagement).
 *
 * User-initiated and one-off: a person sends one recipient the details of a
 * session they thought they'd want. Through the Dispatcher like every other
 * email, so it is logged and subject to the send budget and suppression list —
 * the recipient's address is not ours, so a hard bounce or a prior complaint
 * must still stop it.
 */
final class WebinarShared extends LoggedMail
{
    public function __construct(
        public readonly Webinar $webinar,
        public readonly string $sharerName,
        public readonly string $sharerEmail,
        public readonly string $recipient,
        public readonly ?string $note = null,
    ) {}

    public static function emailKey(): string
    {
        return 'marketing.webinar_shared';
    }

    /** @param  array<string, mixed>  $payload */
    public static function rebuild(array $payload): ?static
    {
        $webinar = Webinar::query()->find($payload['webinar_id'] ?? null);

        if ($webinar === null) {
            return null;
        }

        return new self(
            $webinar,
            (string) ($payload['sharer_name'] ?? ''),
            (string) ($payload['sharer_email'] ?? ''),
            (string) ($payload['recipient'] ?? ''),
            isset($payload['note']) ? (string) $payload['note'] : null,
        );
    }

    public function recipientEmail(): string
    {
        return $this->recipient;
    }

    public function relatedRecord(): Webinar
    {
        return $this->webinar;
    }

    /** @return array<string, mixed> */
    public function logPayload(): array
    {
        return [
            'webinar_id' => $this->webinar->getKey(),
            'sharer_name' => $this->sharerName,
            'sharer_email' => $this->sharerEmail,
            'recipient' => $this->recipient,
            'note' => $this->note,
        ];
    }

    protected function subjectLine(): string
    {
        return $this->sharerName.' shared a webinar with you: '.$this->webinar->title;
    }

    protected function markdownView(): string
    {
        return 'emails.marketing.webinar-shared';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        $this->webinar->loadMissing(['speaker', 'accreditations']);

        return [
            'webinar' => $this->webinar,
            'sharerName' => $this->sharerName,
            'sharerEmail' => $this->sharerEmail,
            'note' => $this->note,
            'url' => route('webinars.show', $this->webinar),
            'dateLabel' => $this->webinar->scheduledDateLabel(),
            'timeLabel' => $this->webinar->scheduledTimeLabel(),
            'creditBodies' => $this->webinar->creditBodies(),
        ];
    }
}
