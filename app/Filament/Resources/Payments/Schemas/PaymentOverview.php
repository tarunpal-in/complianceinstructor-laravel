<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Schemas;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Payment;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * One payment, read-only.
 *
 * Nothing here is editable. A payment is a statement about money that has
 * already moved; correcting it means recording a refund, which leaves both
 * numbers on the record.
 */
class PaymentOverview
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()->columns(4)->schema([
                TextEntry::make('gateway')
                    ->badge()
                    ->formatStateUsing(fn (PaymentGateway $state): string => $state->label())
                    ->color(fn (PaymentGateway $state): string => $state->color()),

                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => $state->color()),

                TextEntry::make('amount_cents')->label('Amount')->money('USD', divideBy: 100)->weight('bold'),

                TextEntry::make('processed_at')
                    ->label('Processed')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('Not yet'),
            ]),

            Grid::make(2)->schema([
                Section::make('Against')->schema([
                    TextEntry::make('order.number')
                        ->label('Order')
                        ->url(fn (Payment $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]))
                        ->weight('bold'),

                    TextEntry::make('customer')
                        ->label('Customer')
                        ->state(fn (Payment $record): string => $record->order->customerName()),

                    TextEntry::make('order_total')
                        ->label('Order total')
                        ->state(fn (Payment $record): string => Money::format(
                            $record->order->total_cents,
                            $record->order->currency,
                        )),
                ]),

                Section::make('Reconciliation')->schema([
                    TextEntry::make('refunded')
                        ->label('Refunded so far')
                        ->state(fn (Payment $record): string => Money::format($record->refundedCents())),

                    TextEntry::make('refundable')
                        ->label('Still refundable')
                        ->state(fn (Payment $record): string => Money::format($record->refundableCents()))
                        ->color(fn (Payment $record): string => $record->refundableCents() > 0 ? 'success' : 'gray'),

                    TextEntry::make('trail')
                        ->label('Recorded by')
                        // For a manual payment this is the whole audit trail:
                        // no gateway confirmed it, so who entered it is the only
                        // evidence the money arrived (§9).
                        // `->` not `?->`: `??` already swallows a null read, so
                        // the nullsafe would be decoration.
                        ->state(fn (Payment $record): string => $record->gateway->isManual()
                            ? ($record->recordedBy->name ?? 'Nobody recorded — worth checking')
                            : 'Confirmed by '.$record->gateway->label())
                        ->color(fn (Payment $record): string => $record->gateway->isManual() && $record->recorded_by === null
                            ? 'danger'
                            : 'gray'),

                    TextEntry::make('reference')->label('Reference')->placeholder('—'),
                ]),
            ]),

            Section::make('Gateway identifiers')
                ->columns(2)
                ->schema([
                    TextEntry::make('gateway_payment_id')
                        ->label('Payment ID')
                        ->copyable()
                        // The UNIQUE on this column is what makes fulfilment
                        // idempotent (§14 #5); manual payments carry none and
                        // coexist because SQL treats NULLs as distinct.
                        ->placeholder('None — recorded by hand'),

                    TextEntry::make('currency'),
                ]),

            Section::make('Gateway response')
                ->description('The gateway in its own words, kept verbatim for disputes. Nothing reads it to decide anything.')
                ->collapsed()
                ->schema([
                    TextEntry::make('raw_response')
                        ->hiddenLabel()
                        ->placeholder('Nothing recorded')
                        ->state(fn (Payment $record): HtmlString => self::rawResponse($record)),
                ]),
        ]);
    }

    /**
     * Pretty-printed and escaped.
     *
     * This is the one field on the screen written by an outside party, so it is
     * displayed as text rather than rendered — a gateway that ever returned
     * markup must not be able to put it on an admin page.
     */
    private static function rawResponse(Payment $payment): HtmlString
    {
        $raw = $payment->raw_response;

        if (blank($raw)) {
            return new HtmlString('<span class="text-gray-400">Nothing recorded</span>');
        }

        $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return new HtmlString(
            '<pre class="overflow-x-auto text-xs leading-5">'.e((string) $json).'</pre>'
        );
    }
}
