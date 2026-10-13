<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderAttendee;
use App\Models\User;

/**
 * Tells someone a seat was booked for them — CLAUDE.md §15.
 *
 * This audience buys seats for a team, so the attendee is frequently not the
 * purchaser. Required, because the attendee did not buy it and has no other way
 * of learning it exists: no order in their account, no receipt, nothing — until
 * this. Sent only to a seat whose email differs from the purchaser's, so the
 * buyer is not told about their own seat twice.
 */
final class SeatAssigned extends LoggedMail
{
    public function __construct(public readonly OrderAttendee $attendee) {}

    public static function emailKey(): string
    {
        return 'purchase.seat_assigned';
    }

    /** @param  array<string, mixed>  $payload */
    public static function rebuild(array $payload): ?static
    {
        $attendee = OrderAttendee::query()
            ->with(['orderItem.order', 'orderItem.webinar', 'user'])
            ->find($payload['attendee_id'] ?? null);

        return $attendee === null ? null : new self($attendee);
    }

    public function recipientEmail(): string
    {
        return (string) $this->attendee->email;
    }

    public function recipientName(): string
    {
        return (string) $this->attendee->name;
    }

    public function recipientUser(): ?User
    {
        return $this->attendee->user;
    }

    public function relatedRecord(): Order
    {
        return $this->order();
    }

    /** @return array<string, mixed> */
    public function logPayload(): array
    {
        return ['attendee_id' => $this->attendee->getKey()];
    }

    protected function subjectLine(): string
    {
        return 'A seat has been booked for you: '.$this->webinarTitle();
    }

    protected function markdownView(): string
    {
        return 'emails.purchase.seat-assigned';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        $order = $this->order();

        return [
            'attendee' => $this->attendee,
            'order' => $order,
            'webinarTitle' => $this->webinarTitle(),
            'purchaserName' => $order->customerName(),
        ];
    }

    private function order(): Order
    {
        return $this->attendee->loadMissing('orderItem.order')->orderItem->order;
    }

    private function webinarTitle(): string
    {
        $item = $this->attendee->loadMissing('orderItem')->orderItem;

        // ->value() rather than ->webinar->title: a package line has no webinar,
        // and the relation is typed non-null, so reading the column through the
        // query returns null honestly and falls back to the line description.
        $title = $item->webinar()->value('title');

        return is_string($title) ? $title : $item->description;
    }
}
