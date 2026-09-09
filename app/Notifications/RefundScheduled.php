<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Payments\RequestRefund;
use App\Enums\UserRole;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Refund;
use App\Models\User;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Tells everyone who could stop a refund that one has been agreed.
 *
 * The point of the mail is the window it announces. It goes to every Super
 * Admin — including the person who raised it, because the case this guards
 * against is an account being used by someone other than its owner, and that
 * owner has to hear about it.
 *
 * Queued, per §4 rule 7: an SMTP round trip must not sit inside the request
 * that agreed the refund.
 */
class RefundScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Refund $refund) {}

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
            $approver->notify(new self($refund));
        }
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $refund = $this->refund->loadMissing(['payment.order', 'issuedBy']);
        $payment = $refund->payment;
        $order = $payment->order;

        $amount = Money::format($refund->amount_cents, $payment->currency);
        $window = RequestRefund::delayMinutes();

        return (new MailMessage)
            ->subject("Refund of {$amount} scheduled on order {$order->number}")
            ->greeting('A refund has been scheduled.')
            ->line("**{$amount}** will be refunded to the customer on order **{$order->number}** via {$payment->gateway->label()}.")
            ->line('Raised by: '.($refund->issuedBy->name ?? 'an administrator'))
            ->line('Reason given: '.($refund->reason ?: 'none'))
            // The whole reason for the mail. Saying when, not just that, is what
            // makes it actionable.
            ->line($window > 0
                ? "It will be sent to the gateway in about {$window} minutes. Until then it can be stopped."
                : 'It will be sent to the gateway within the minute.')
            ->action('Review this payment', PaymentResource::getUrl('view', ['record' => $payment->getKey()]))
            ->line('If you did not expect this, open it and cancel it now.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'refund_id' => $this->refund->id,
            'amount_cents' => $this->refund->amount_cents,
        ];
    }
}
