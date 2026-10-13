<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use App\Support\Money;

/**
 * The payment did not go through — CLAUDE.md §14 #6's near neighbour.
 *
 * Required, because the alternative is silence after a failed capture: the
 * customer believes they have bought something, has not, and hears nothing. The
 * cart is kept on a decline (only a success clears it), so the honest next step
 * is "your items are still there" rather than a deep link into a payment page a
 * guest can no longer open.
 *
 * No expiring links: it points at the cart, a plain page, so the stored body
 * stays truthful and a resend can replay it as-is.
 */
final class PaymentDeclined extends LoggedMail
{
    public function __construct(public readonly Order $order) {}

    public static function emailKey(): string
    {
        return 'purchase.payment_declined';
    }

    /** @param  array<string, mixed>  $payload */
    public static function rebuild(array $payload): ?static
    {
        $order = Order::query()->find($payload['order_id'] ?? null);

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
        return 'We could not take payment for order '.$this->order->number;
    }

    protected function markdownView(): string
    {
        return 'emails.purchase.payment-declined';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [
            'order' => $this->order,
            'amount' => Money::format($this->order->total_cents, $this->order->currency),
            'cartUrl' => route('cart.index'),
        ];
    }
}
