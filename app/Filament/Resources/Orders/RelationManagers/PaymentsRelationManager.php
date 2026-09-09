<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
 *    refundable, and sent to the gateway once its cooling-off window closes.
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
                    ->default(fn (): string => Money::toDecimal($this->order()->outstandingCents()))
                    ->helperText(fn (): string => 'Outstanding on this order: '
                        .Money::toDecimal($this->order()->outstandingCents())),

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
                    ->state(fn (Payment $record): string => Money::format($record->refundedCents()))
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
                        $data['amount_cents'] = Money::toCents($data['amount'] ?? 0);

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
     * The same action backs the Payments screen. Two screens offering a
     * slightly different refund is how a bank reconciliation ends up
     * disagreeing with the database, so both mount the same action.
     *
     * P3 puts a gateway call in front of it and revokes the entitlements the
     * order granted (§5.2). Until those exist this records the decision and the
     * audit trail rather than pretending money moved, and says so.
     */
    /**
     * Literally the payment screen's action.
     *
     * It used to be a near-copy, which is how two screens end up scheduling
     * refunds on subtly different terms — a different window, a different
     * message, a different ceiling. There is one definition and both places
     * mount it.
     */
    private function refundAction(): Action
    {
        return ViewPayment::refundAction();
    }

    /**
     * Bring the stored status back in line with the payment rows.
     *
     * §9 — the status is derived, so anything that changes a payment has to
     * re-derive it rather than leave the column asserting something the money
     * no longer supports. Releasing a refund does this for itself; recording a
     * payment goes through here.
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
}
