<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The order summary.
 *
 * Read-only by design. An order is a financial record; §14 #4 has the
 * predecessor writing six tables in sequence with no transaction *after* taking
 * money, and an editable total here would be the same class of mistake at
 * leisure. Money moves through the Refund and Record-payment actions, which are
 * gated on their own permissions and leave an audit trail.
 */
class OrderOverview
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Order number')->copyable()->weight('bold'),

                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),

                TextEntry::make('placed_at')->label('Placed')->dateTime('j M Y, H:i'),

                TextEntry::make('currency')->label('Currency'),
            ]),

            /*
             * §9 — order status is derived from payments, never from input. The
             * derived value is shown beside the stored one so a disagreement is
             * visible here rather than discovered from a support ticket.
             */
            Section::make('Payment reconciliation')
                ->description('What the payment records say, independently of the status column.')
                ->columns(4)
                ->schema([
                    TextEntry::make('captured')
                        ->label('Captured')
                        ->state(fn (Order $record): string => self::money($record->capturedCents())),

                    TextEntry::make('refunded')
                        ->label('Refunded')
                        ->state(fn (Order $record): string => self::money($record->refundedCents())),

                    TextEntry::make('outstanding')
                        ->label('Outstanding')
                        ->state(fn (Order $record): string => self::money($record->outstandingCents()))
                        ->color(fn (Order $record): string => $record->outstandingCents() > 0 ? 'warning' : 'gray'),

                    TextEntry::make('derived')
                        ->label('Derived status')
                        ->badge()
                        ->state(fn (Order $record): string => $record->derivedStatus()->label())
                        ->color(fn (Order $record): string => $record->statusMatchesPayments() ? 'success' : 'danger')
                        ->helperText(fn (Order $record): ?string => $record->statusMatchesPayments()
                            ? null
                            : 'This disagrees with the stored status — a webhook was probably missed.'),
                ]),

            Grid::make(2)->schema([
                Section::make('Customer')->schema([
                    TextEntry::make('customer_name')
                        ->label('Name')
                        ->state(fn (Order $record): string => $record->customerName()),

                    TextEntry::make('customer_email')
                        ->label('Email')
                        ->state(fn (Order $record): string => $record->customerEmail())
                        ->copyable(),

                    TextEntry::make('account')
                        ->label('Account')
                        ->state(fn (Order $record): string => $record->user_id === null
                            ? 'Guest checkout'
                            : 'Registered customer'),

                    TextEntry::make('billing_snapshot')
                        ->label('Billing details')
                        // Frozen at placement: a historic invoice must never
                        // change because the customer edited their address.
                        ->state(fn (Order $record): HtmlString => self::snapshot($record))
                        ->columnSpanFull(),
                ]),

                Section::make('Totals')->schema([
                    TextEntry::make('subtotal_cents')->label('Subtotal')->money('USD', divideBy: 100),
                    TextEntry::make('discount_cents')->label('Discount')->money('USD', divideBy: 100),
                    TextEntry::make('tax_cents')->label('Tax')->money('USD', divideBy: 100),
                    TextEntry::make('total_cents')->label('Total')->money('USD', divideBy: 100)->weight('bold')->size('lg'),
                ]),
            ]),

            Section::make('Internal notes')
                ->collapsed()
                ->schema([
                    TextEntry::make('notes')->hiddenLabel()->placeholder('None'),
                ]),
        ]);
    }

    private static function money(int $cents): string
    {
        // Integer arithmetic to the last step (§4 rule 6); only the render
        // divides, and never a float multiplication.
        return '$'.number_format($cents / 100, 2);
    }

    private static function snapshot(Order $order): HtmlString
    {
        $snapshot = $order->billing_snapshot ?? [];

        if ($snapshot === []) {
            return new HtmlString('<span class="text-gray-400">Nothing captured</span>');
        }

        $lines = [];

        foreach ($snapshot as $key => $value) {
            if (blank($value) || is_array($value)) {
                continue;
            }

            $lines[] = '<strong>'.e(ucfirst(str_replace('_', ' ', (string) $key))).':</strong> '.e((string) $value);
        }

        return new HtmlString(implode('<br>', $lines));
    }
}
