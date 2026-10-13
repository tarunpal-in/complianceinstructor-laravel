<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Models\User;

/**
 * The receipt for a paid order — CLAUDE.md §14 #6.
 *
 * Required, and the lock reason is the predecessor's worst defect: a customer
 * who paid and received nothing. This goes out the moment RecordCapture writes
 * the first succeeded payment, so the paid order and the email that proves it
 * are the same event.
 *
 * It carries a signed, expiring link to the invoice PDF rather than the file
 * itself (§9), which is why it declares expiring links: a body stored today
 * holds a link that dies in thirty days, so a resend rebuilds it fresh rather
 * than replaying a dead one.
 */
final class OrderConfirmation extends LoggedMail
{
    public function __construct(public readonly Order $order) {}

    public static function emailKey(): string
    {
        return 'purchase.order_confirmation';
    }

    /** @param  array<string, mixed>  $payload */
    public static function rebuild(array $payload): ?static
    {
        $order = Order::query()
            ->with(['items.formats', 'items.attendees'])
            ->find($payload['order_id'] ?? null);

        return $order === null ? null : new self($order);
    }

    public function recipientEmail(): string
    {
        return $this->order->customerEmail();
    }

    public function recipientName(): string
    {
        return $this->order->customerName();
    }

    public function recipientUser(): ?User
    {
        return $this->order->customer();
    }

    public function relatedRecord(): Order
    {
        return $this->order;
    }

    /** @return array<string, mixed> */
    public function logPayload(): array
    {
        return ['order_id' => $this->order->getKey()];
    }

    protected function subjectLine(): string
    {
        return 'Your order '.$this->order->number.' is confirmed';
    }

    protected function markdownView(): string
    {
        return 'emails.purchase.order-confirmation';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        $this->order->loadMissing(['items.formats']);

        return [
            'order' => $this->order,
            'invoiceUrl' => $this->order->invoiceUrl(),
        ];
    }
}
