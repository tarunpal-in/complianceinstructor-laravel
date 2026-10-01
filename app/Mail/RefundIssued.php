<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Support\Money;

/**
 * Money has gone back to the customer.
 *
 * Required, because the alternative is a credit appearing on a card statement
 * with a merchant descriptor and no explanation — which generates a support
 * ticket at best and a chargeback dispute at worst. Until now only staff were
 * told a refund had happened; the person receiving the money was not.
 */
final class RefundIssued extends LoggedMail
{
    public function __construct(public readonly Refund $refund) {}

    public static function emailKey(): string
    {
        return 'purchase.refund_issued';
    }

    /** @param  array<string, mixed>  $payload */
    public static function rebuild(array $payload): ?static
    {
        $refund = Refund::query()
            ->with(['payment.order'])
            ->find($payload['refund_id'] ?? null);

        return $refund === null ? null : new self($refund);
    }

    public function recipientEmail(): string
    {
        return $this->order()->customerEmail();
    }

    public function recipientName(): string
    {
        return $this->order()->customerName();
    }

    public function recipientUser(): ?User
    {
        return $this->order()->customer();
    }

    /**
     * The order, not the refund.
     *
     * "Did they hear about the refund?" is asked while looking at the order —
     * which is also the only one of the two with an admin screen to link to.
     */
    public function relatedRecord(): Order
    {
        return $this->order();
    }

    /** @return array<string, mixed> */
    public function logPayload(): array
    {
        return ['refund_id' => $this->refund->getKey()];
    }

    protected function subjectLine(): string
    {
        return 'Your refund for order '.$this->order()->number;
    }

    protected function markdownView(): string
    {
        return 'emails.purchase.refund-issued';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        $order = $this->order();

        return [
            'order' => $order,
            'refund' => $this->refund,
            'amount' => Money::format($this->refund->amount_cents, $order->currency),
            'gateway' => $this->refund->payment->gateway->label(),
            // The question the customer will actually have. Nobody asks what a
            // refund is; they ask when it will show up.
            'settlesInDays' => 10,
        ];
    }

    private function order(): Order
    {
        return $this->refund->loadMissing('payment.order')->payment->order;
    }
}
