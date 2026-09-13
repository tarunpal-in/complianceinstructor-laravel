<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What this customer has bought.
 *
 * The first thing anybody opens a customer record to see, and until now it was
 * only reachable from the other end — you could find the customer on an order,
 * but not the orders on a customer.
 *
 * Read-only, and each row links to the order itself. An order is a financial
 * record: §14 #4 has the predecessor writing six tables in sequence with no
 * transaction after taking money, and an editable total on a customer screen
 * would be the same class of mistake at leisure. Money moves through the
 * Refund and Record-payment actions, which are gated and leave a trail.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Orders';

    protected static ?string $modelLabel = 'order';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('items'))
            ->columns([
                TextColumn::make('number')
                    ->label('Order')
                    ->weight('bold')
                    ->searchable()
                    // Straight through to the order, because the next question
                    // after "what did they buy" is always "what happened to it".
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),

                TextColumn::make('placed_at')
                    ->label('Placed')
                    ->dateTime('j M Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),

                TextColumn::make('items_count')
                    ->label('Lines')
                    // No ->counts(): the query above already adds the alias, and
                    // adding it twice puts the same alias in the select twice,
                    // which MySQL rejects outright.
                    ->alignEnd(),

                TextColumn::make('total_cents')
                    ->label('Total')
                    ->money('USD', divideBy: 100)
                    ->alignEnd()
                    /*
                     * Summed per order rather than shown as one lifetime figure
                     * on the customer list. A single "spent" number would have
                     * to net off refunds to be true, and a subtly wrong money
                     * total is worse than none — here each order carries its own
                     * status, so a refunded one is visible as such.
                     */
                    ->summarize(
                        Sum::make()
                            ->label('Ordered in total')
                            ->money('USD', divideBy: 100)
                            ->query(fn (QueryBuilder $query): QueryBuilder => $query)
                    ),
            ])
            ->defaultSort('placed_at', 'desc')
            ->emptyStateHeading('Nothing bought yet')
            ->emptyStateDescription('Orders appear here as this customer buys webinars or packages.');
    }
}
