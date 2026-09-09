<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Actions\Payments\RequestRefund;
use App\Enums\RefundStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use RuntimeException;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    public function getTitle(): string
    {
        /** @var Payment $record */
        $record = $this->getRecord();

        return Money::format($record->amount_cents, $record->currency)
            .' · '.$record->gateway->label();
    }

    protected function getHeaderActions(): array
    {
        return [
            self::refundAction(),
            $this->openOrderAction(),
        ];
    }

    /**
     * Agreeing a refund, not sending one.
     *
     * The same action backs the order screen, and both call RequestRefund —
     * which caps the amount under a lock, writes it as scheduled and emails
     * everyone who could stop it. Nothing reaches the gateway until the window
     * closes.
     */
    public static function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            // Super Admin only. Refunding is the one admin action that moves
            // money out irreversibly, so it is not part of ordinary Finance
            // work — see RolePermissionSeeder.
            ->visible(fn (Payment $record): bool => $record->refundableCents() > 0
                && (auth()->user()?->can('refund_payment') ?? false))
            ->modalHeading('Refund this payment')
            ->modalDescription(fn (): string => self::windowDescription())
            ->modalSubmitActionLabel('Schedule refund')
            ->schema(fn (Payment $record): array => [
                // The order's position, not just this payment's. An order can
                // carry several payments and the ceiling on this one says
                // nothing about what has already gone back on the others.
                Text::make(fn (): HtmlString => self::orderPosition($record))
                    ->columnSpanFull(),

                TextInput::make('amount')
                    ->label('Amount to refund')
                    ->prefix('$')
                    ->required()
                    ->numeric()
                    ->default(Money::toDecimal($record->refundableCents()))
                    ->helperText('At most '.Money::toDecimal($record->refundableCents()).' remains refundable.')
                    // Checked here for a readable message, and again inside the
                    // action under a lock — two people refunding the same
                    // payment in different tabs would both pass this one.
                    ->rule(function () use ($record): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (Money::toCents($value) > $record->refundableCents()) {
                                $fail('That is more than remains refundable on this payment.');
                            }
                        };
                    }),

                Textarea::make('reason')
                    ->required()
                    ->rows(2)
                    ->maxLength(255)
                    ->helperText('Kept on the record, and included in the email. The customer never sees it.'),
            ])
            ->action(function (Payment $record, array $data): void {
                try {
                    $refund = app(RequestRefund::class)(
                        $record,
                        Money::toCents($data['amount']),
                        (string) $data['reason'],
                        auth()->id(),
                    );
                } catch (RuntimeException $e) {
                    // A refusal the person can act on — another refund already
                    // in flight, or an amount past the ceiling. Shown as itself
                    // rather than as a 500, and persistent because it says what
                    // to do next.
                    Notification::make()
                        ->title('That refund was not raised')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                self::notifyOfRequest($refund);
            });
    }

    /**
     * Stopping one during its window.
     *
     * Without this the delay is just latency. It is the reason the email is
     * worth sending.
     */
    public static function cancelRefundAction(): Action
    {
        return Action::make('cancelRefund')
            ->label('Cancel refund')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->visible(fn (Refund $record): bool => $record->isStoppable()
                && (auth()->user()?->can('refund_payment') ?? false))
            ->requiresConfirmation()
            ->modalHeading('Stop this refund?')
            ->modalDescription('Nothing has been sent yet. The amount goes back to the payment and can be refunded again later.')
            ->action(function (Refund $record): void {
                app(\App\Actions\Payments\CancelScheduledRefund::class)($record, auth()->id());

                Notification::make()
                    ->title('Refund cancelled')
                    ->body('Nothing was sent to the gateway.')
                    ->success()
                    ->send();
            });
    }

    /**
     * How this order stands, so a second partial refund is raised with open
     * eyes rather than from one payment's ceiling alone.
     */
    private static function orderPosition(Payment $payment): HtmlString
    {
        $order = $payment->order;
        $order->load('payments.refunds');

        $lines = [
            'Captured on order <strong>'.e($order->number).'</strong>: '
                .e(Money::format($order->capturedCents(), $order->currency)),
        ];

        if ($order->refundedCents() > 0) {
            $lines[] = 'Already refunded: <strong>'
                .e(Money::format($order->refundedCents(), $order->currency)).'</strong>';
        }

        if ($order->refundsInFlightCents() > 0) {
            $lines[] = '<span class="text-warning-600 dark:text-warning-400">Scheduled and not yet sent: <strong>'
                .e(Money::format($order->refundsInFlightCents(), $order->currency)).'</strong></span>';
        }

        return new HtmlString(implode('<br>', $lines));
    }

    /** What the modal says about the wait, in the client's configured terms. */
    private static function windowDescription(): string
    {
        $minutes = RequestRefund::delayMinutes();

        if ($minutes <= 0) {
            return 'It will be sent to the gateway within the minute. Every administrator is emailed.';
        }

        return sprintf(
            'It is not sent immediately. Every administrator is emailed now, and it goes to the gateway in %d minutes — until then it can be cancelled.',
            $minutes,
        );
    }

    /**
     * Says what actually happened: agreed, not refunded.
     *
     * Reporting "refund sent" here would be a lie for the next hour, and the
     * kind that only surfaces when a customer asks where their money is.
     */
    public static function notifyOfRequest(Refund $refund): void
    {
        Notification::make()
            ->title('Refund scheduled')
            ->body($refund->status === RefundStatus::Scheduled && $refund->scheduled_for !== null
                ? 'It goes to the gateway '.$refund->scheduled_for->diffForHumans().'. Every administrator has been emailed, and it can be cancelled until then.'
                : 'Every administrator has been emailed.')
            ->success()
            ->send();
    }

    private function openOrderAction(): Action
    {
        return Action::make('order')
            ->label(fn (Payment $record): string => 'Open '.$record->order->number)
            ->icon(Heroicon::OutlinedShoppingBag)
            ->color('gray')
            ->url(fn (Payment $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]));
    }
}
