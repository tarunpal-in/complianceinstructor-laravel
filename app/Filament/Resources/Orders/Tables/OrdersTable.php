<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Models\Order;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Order')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('customer')
                    ->label('Customer')
                    // Composed rather than a join: a guest order has no user and
                    // its name lives in the frozen billing snapshot.
                    ->state(fn (Order $record): string => $record->customerName())
                    ->description(fn (Order $record): string => $record->customerEmail())
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query
                            ->whereHas('user', fn (Builder $user): Builder => $user
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"))
                            ->orWhere('billing_snapshot->name', 'like', "%{$search}%")
                            ->orWhere('billing_snapshot->email', 'like', "%{$search}%");
                    })
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Lines')
                    ->counts('items')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('total_cents')
                    ->label('Total')
                    ->money('USD', divideBy: 100)
                    ->sortable()
                    ->alignEnd()
                    // §14 #7 — a revenue sum that quietly counts unit prices, or
                    // refunded orders, is worse than no sum at all. Only settled
                    // orders contribute.
                    ->summarize(
                        Sum::make()
                            ->label('Settled revenue')
                            ->money('USD', divideBy: 100)
                            // A query builder, not an Eloquent one, so the model
                            // scope is unavailable — and spelling the statuses
                            // out is worth it anyway at the point money is
                            // added up.
                            ->query(fn (QueryBuilder $query): QueryBuilder => $query->whereIn('status', [
                                OrderStatus::Paid->value,
                                OrderStatus::PartiallyRefunded->value,
                            ]))
                    ),

                TextColumn::make('gateway')
                    ->label('Paid via')
                    ->state(fn (Order $record): string => $record->payments
                        ->pluck('gateway')
                        ->map(fn (PaymentGateway $gateway): string => $gateway->label())
                        ->unique()
                        ->join(', ') ?: '—')
                    ->toggleable(),

                TextColumn::make('placed_at')
                    ->label('Placed')
                    ->dateTime('j M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('placed_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatus::options())
                    ->multiple(),

                SelectFilter::make('gateway')
                    ->label('Gateway')
                    ->options(PaymentGateway::options())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('payments', fn (Builder $payments): Builder => $payments
                            ->where('gateway', $data['value']))
                        : $query),

                TernaryFilter::make('guest')
                    ->label('Account')
                    ->placeholder('Any')
                    ->trueLabel('Guest checkout')
                    ->falseLabel('Signed-in customer')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('user_id'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('user_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Filter::make('placed_between')
                    ->schema([
                        DatePicker::make('from')->label('Placed from'),
                        DatePicker::make('until')->label('Placed until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from): Builder => $q
                            ->whereDate('placed_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until): Builder => $q
                            ->whereDate('placed_at', '<=', $until)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Placed from '.$data['from'];
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Placed until '.$data['until'];
                        }

                        return $indicators;
                    }),

                /*
                 * The one that finds broken records. §9 requires an order's
                 * status be derived from its payments; this surfaces every row
                 * where the stored column no longer agrees — a missed webhook,
                 * or someone editing the database by hand.
                 */
                Filter::make('status_mismatch')
                    ->label('Status disagrees with payments')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn(
                        'id',
                        Order::query()
                            ->with('payments.refunds')
                            ->get()
                            ->reject(fn (Order $order): bool => $order->statusMatchesPayments())
                            ->modelKeys(),
                    )),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([ViewAction::make()])
            ->toolbarActions([BulkActionGroup::make([])])
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('Orders appear here as soon as checkout is live.');
    }
}
