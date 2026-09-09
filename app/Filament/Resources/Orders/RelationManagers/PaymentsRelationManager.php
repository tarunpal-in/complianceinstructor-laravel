<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The money.
 *
 * Two things happen here, both gated on their own permission rather than on
 * ordinary order editing:
 *
 *  - **Record a manual payment.** This audience is B2B and frequently invoiced
 *    (§1), so a bank transfer has to be recordable. It is the only path that
 *    creates a payment without a gateway confirming it, which is why it needs
 *    `manage_manual_payment` and writes to the activity log.
 *  - **Refund.** Gated on `refund_payment`, capped at what is actually
 *    refundable, and — once the gateway drivers land in P3 — dispatched through
 *    them rather than written straight to the table.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    protected static ?string $modelLabel = 'payment';

    /**
     * Filament makes relation managers read-only on a View page by default, on
     * the reasonable assumption that a view page views things.
     *
     * That assumption is wrong here. The order itself is read-only — there is
     * no edit page and no create page, deliberately — but recording an invoiced
     * payment and refunding one are the two things this screen exists to do,
     * and both are separately permissioned below.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Recording a payment authorizes on its own permission.
     *
     * Without this it inherits OrderResource::canCreate(), which is false on
     * purpose — orders are minted by checkout, not by people. Adding a payment
     * to an existing order is a different act with a different owner.
     */
    public function canCreate(): bool
    {
        return auth()->user()?->can('manage_manual_payment') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('gateway')
                    ->options([PaymentGateway::Manual->value => PaymentGateway::Manual->label()])
                    ->default(PaymentGateway::Manual->value)
                    ->required()
                    ->disabled()
                    ->dehydrated()
                    // Stripe and PayPal payments are written by their webhooks,
                    // never typed in. Allowing one to be recorded here by hand
                    // would let someone mark an order paid without money having
                    // moved — §14 #2 in a different costume.
                    ->helperText('Only invoiced payments can be recorded by hand.'),

                TextInput::make('reference')
                    ->label('Reference')
                    ->required()
                    ->maxLength(120)
                    ->helperText('The bank reference, cheque number or PO this arrived against.'),

                // Left dehydrated even though there is no `amount` column: the
                // create hook converts it to `amount_cents` and then drops it.
                // Excluding it would hand the hook nothing and quietly record a
                // payment of zero.
                TextInput::make('amount')
                    ->label('Amount')
                    ->prefix('$')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->default(fn (): string => self::toDecimal($this->order()->outstandingCents()))
                    ->helperText(fn (): string => 'Outstanding on this order: '
                        .self::toDecimal($this->order()->outstandingCents())),

                DateTimePicker::make('processed_at')
                    ->label('Received on')
                    ->seconds(false)
                    ->default(now())
                    ->required(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('gateway_payment_id')
            ->columns([
                TextColumn::make('gateway')
                    ->badge()
                    ->formatStateUsing(fn (PaymentGateway $state): string => $state->label())
                    ->color(fn (PaymentGateway $state): string => $state->color()),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => $state->color()),

                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->money('USD', divideBy: 100)
                    ->alignEnd(),

                TextColumn::make('refunded')
                    ->label('Refunded')
                    ->state(fn (Payment $record): string => '$'.number_format($record->refundedCents() / 100, 2))
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('gateway_payment_id')
                    ->label('Gateway ID')
                    ->placeholder('—')
                    ->copyable()
                    ->limit(24)
                    ->toggleable(),

                TextColumn::make('reference')->placeholder('—')->toggleable(),

                TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('Not yet'),
            ])
            ->defaultSort('created_at')
            ->headerActions([
                CreateAction::make()
                    ->label('Record payment')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->modalHeading('Record an invoiced payment')
                    ->modalDescription('For a bank transfer or cheque against an invoice. Card payments are recorded by the gateway.')
                    ->visible(fn (): bool => auth()->user()?->can('manage_manual_payment') ?? false)
                    ->mutateDataUsing(function (array $data): array {
                        $data['gateway'] = PaymentGateway::Manual->value;
                        $data['status'] = PaymentStatus::Succeeded->value;
                        $data['gateway_payment_id'] = null;
                        $data['currency'] = $this->order()->currency;
                        $data['recorded_by'] = auth()->id();
                        $data['amount_cents'] = self::toCents($data['amount'] ?? 0);

                        unset($data['amount']);

                        return $data;
                    })
                    ->after(function (): void {
                        $this->syncOrderStatus();
                    }),
            ])
            ->recordActions([
                $this->refundAction(),
            ])
            ->emptyStateHeading('No payments recorded')
            ->emptyStateDescription('Nothing has been captured against this order.');
    }

    /**
     * Refunding writes the refund row and moves the order status with it.
     *
     * P3 puts a gateway call in front of this and revokes the entitlements the
     * order granted (§5.2). Until those exist, this records the decision and
     * the audit trail rather than pretending money moved — the notification
     * says so plainly rather than implying a refund was sent.
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
            ->modalDescription('Recorded against the payment. Once the gateway drivers land this also sends the refund and revokes the access it granted.')
            ->schema(fn (Payment $record): array => [
                TextInput::make('amount')
                    ->label('Amount to refund')
                    ->prefix('$')
                    ->required()
                    ->numeric()
                    ->default(self::toDecimal($record->refundableCents()))
                    ->helperText('At most '.self::toDecimal($record->refundableCents()).' remains refundable.')
                    // A ceiling checked server-side, not only in the field.
                    // Refunding more than was taken surfaces weeks later in a
                    // bank reconciliation, which is the worst place to find it.
                    ->rule(function () use ($record): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (self::toCents($value) > $record->refundableCents()) {
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
                Refund::create([
                    'payment_id' => $record->id,
                    'status' => RefundStatus::Succeeded,
                    'amount_cents' => self::toCents($data['amount']),
                    'reason' => $data['reason'],
                    'issued_by' => auth()->id(),
                    'processed_at' => now(),
                ]);

                $this->syncOrderStatus();

                Notification::make()
                    ->title('Refund recorded')
                    ->body('The order status has been updated. Sending it to the gateway and revoking access arrive with the payment drivers.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Bring the stored status back in line with the payment rows.
     *
     * §9 — the status is derived, so anything that changes a payment has to
     * re-derive it rather than leave the column asserting something the money
     * no longer supports.
     */
    private function syncOrderStatus(): void
    {
        $order = $this->order();
        $order->load('payments.refunds');

        $order->update(['status' => $order->derivedStatus()]);
    }

    private function order(): Order
    {
        /** @var Order $order */
        $order = $this->getOwnerRecord();

        return $order;
    }

    private static function toDecimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Parsed as a string, never multiplied as a float: (float) '19.99' * 100 is
     * 1998.9999999999998, and casting that truncates to 1998 (§4 rule 6).
     */
    private static function toCents(mixed $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim((string) $value), 2), 2, '0');

        $whole = (int) preg_replace('/\D/', '', $whole);
        $fraction = (int) str_pad(substr((string) preg_replace('/\D/', '', $fraction), 0, 2), 2, '0');

        return $whole * 100 + $fraction;
    }
}
