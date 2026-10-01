<?php

declare(strict_types=1);

namespace App\Mail;

use App\Actions\Payments\RequestRefund;
use App\Enums\UserRole;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Tells everyone who could stop a refund that one has been agreed.
 *
 * The point of the mail is the window it announces. It goes to every Super
 * Admin — including the person who raised it, because the case this guards
 * against is an account being used by someone other than its owner, and that
 * owner has to hear about it.
 *
 * Was a Notification; now a mailable, so it goes through the Dispatcher and lands
 * in the log like everything else. Nothing about who receives it or why has
 * changed. The `via()`/`toArray()` pair went with it: `via()` only ever returned
 * `['mail']`, so the database channel it implied was never used.
 */
final class RefundScheduled extends LoggedMail
{
    public function __construct(
        public readonly Refund $refund,
        public readonly User $approver,
    ) {}

    public static function emailKey(): string
    {
        return 'internal.refund_scheduled';
    }

    /**
     * Send it to everyone with the authority to stop it.
     *
     * Resolved from the role rather than a configured address, so adding an
     * administrator adds them to this automatically and a departed one stops
     * receiving it.
     */
    public static function notifyApprovers(Refund $refund): void
    {
        $approvers = User::query()
            ->role(UserRole::SuperAdmin->value)
            ->whereNotNull('email')
            ->get();

        if ($approvers->isEmpty()) {
            // Not fatal — the refund is agreed and will still go out — but it
            // means the safeguard is doing nothing, which somebody should know.
            Log::warning('A refund was scheduled with no Super Admin to notify.', [
                'refund_id' => $refund->id,
            ]);

            return;
        }

        foreach ($approvers as $approver) {
            app(\App\Email\Dispatcher::class)->send(new self($refund, $approver));
        }
    }

    /** @param  array<string, mixed>  $payload */
    public static function rebuild(array $payload): ?static
    {
        $refund = Refund::query()->with(['payment.order', 'issuedBy'])->find($payload['refund_id'] ?? null);
        $approver = User::query()->find($payload['approver_id'] ?? null);

        return $refund === null || $approver === null ? null : new self($refund, $approver);
    }

    public function recipientEmail(): string
    {
        return (string) $this->approver->email;
    }

    public function recipientName(): string
    {
        return $this->approver->name;
    }

    public function recipientUser(): User
    {
        return $this->approver;
    }

    public function relatedRecord(): ?Order
    {
        return $this->refund->loadMissing('payment.order')->payment->order;
    }

    /** @return array<string, mixed> */
    public function logPayload(): array
    {
        return [
            'refund_id' => $this->refund->getKey(),
            'approver_id' => $this->approver->getKey(),
        ];
    }

    protected function subjectLine(): string
    {
        $refund = $this->refund->loadMissing('payment.order');
        $amount = Money::format($refund->amount_cents, $refund->payment->currency);

        return "Refund of {$amount} scheduled on order ".$refund->payment->order->number;
    }

    protected function markdownView(): string
    {
        return 'emails.staff.refund-scheduled';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        $refund = $this->refund->loadMissing(['payment.order', 'issuedBy']);
        $payment = $refund->payment;

        return [
            'refund' => $refund,
            'order' => $payment->order,
            'amount' => Money::format($refund->amount_cents, $payment->currency),
            'gateway' => $payment->gateway->label(),
            'raisedBy' => $refund->issuedBy->name ?? 'an administrator',
            'reason' => $refund->reason ?: 'none',
            // The whole reason for the mail. Saying when, not just that, is what
            // makes it actionable.
            'windowMinutes' => RequestRefund::delayMinutes(),
            'reviewUrl' => PaymentResource::getUrl('view', ['record' => $payment->getKey()], panel: 'admin'),
        ];
    }
}
