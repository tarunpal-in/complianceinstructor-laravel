<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Actions\Payments\RecordRefund;
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
use Filament\Support\Icons\Heroicon;

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
            $this->refundAction(),
            $this->openOrderAction(),
        ];
    }

    /**
     * The same refund as on the order screen, and deliberately the same code —
     * both call RecordRefund, which caps the amount under a lock and re-derives
     * the order. Two screens offering a slightly different refund is how a bank
     * reconciliation ends up disagreeing with the database.
     */
    private function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->visible(fn (Payment $record): bool => $record->refundableCents() > 0
                && (auth()->user()?->can('refund_payment') ?? false))
            ->modalHeading('Refund this payment')
            ->modalDescription(fn (Payment $record): string => $record->gateway->isManual()
                ? 'Recorded against the payment. Send the money back yourself — an invoiced payment has no gateway to do it.'
                : 'Sent to '.$record->gateway->label().' and recorded here. Revoking the access it granted arrives with the entitlements ledger in P3.')
            ->schema(fn (Payment $record): array => [
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
                    ->helperText('Kept on the record. The customer never sees it.'),
            ])
            ->action(function (Payment $record, array $data): void {
                $refund = app(RecordRefund::class)(
                    $record,
                    Money::toCents($data['amount']),
                    (string) $data['reason'],
                    auth()->id(),
                );

                self::notifyOf($refund, $record);
            });
    }

    /**
     * A decline is an outcome, not an error.
     *
     * The gateway refusing has to read as plainly as it succeeding, and it must
     * not vanish in four seconds — the admin has to know the money did not move
     * and why, or they will assume it did.
     */
    public static function notifyOf(Refund $refund, Payment $payment): void
    {
        if ($refund->status === RefundStatus::Succeeded) {
            Notification::make()
                ->title($payment->gateway->isManual() ? 'Refund recorded' : 'Refund sent to '.$payment->gateway->label())
                ->body('The order status has been updated to match.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('The refund was refused')
            ->body($refund->gateway_message ?? 'The gateway gave no reason. Nothing has been refunded.')
            ->danger()
            ->persistent()
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
