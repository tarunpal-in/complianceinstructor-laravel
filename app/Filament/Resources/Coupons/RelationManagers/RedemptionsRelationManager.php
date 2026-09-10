<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\CouponRedemption;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The redemption log — §8 improvement #2, "every use writes a
 * `coupon_redemptions` row".
 *
 * Read-only. Deleting a redemption would hand back a redemption the customer
 * already spent, and the discount is on an invoice that cannot change. The
 * total at the foot is the number that matters: what this promotion actually
 * cost.
 */
class RedemptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'redemptions';

    protected static ?string $title = 'Redemptions';

    protected static ?string $modelLabel = 'redemption';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('redeemed_at')
                    ->label('Used')
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('order.number')
                    ->label('Order')
                    ->weight('bold')
                    ->url(fn (CouponRedemption $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),

                TextColumn::make('customer')
                    ->label('Customer')
                    // Composed rather than joined: a guest redemption has no
                    // user, and its name lives in the order's billing snapshot.
                    ->state(fn (CouponRedemption $record): string => $record->order->customerName())
                    ->description(fn (CouponRedemption $record): string => $record->user_id === null
                        ? 'Guest checkout'
                        : 'Signed in'),

                TextColumn::make('amount_cents')
                    ->label('Discount')
                    ->money('USD', divideBy: 100)
                    ->alignEnd()
                    // What the promotion cost, which is the question a discount
                    // is eventually judged on.
                    ->summarize(
                        Sum::make()
                            ->label('Total discounted')
                            ->money('USD', divideBy: 100)
                            ->query(fn (QueryBuilder $query): QueryBuilder => $query)
                    ),
            ])
            ->defaultSort('redeemed_at', 'desc')
            ->emptyStateHeading('Not used yet')
            ->emptyStateDescription('Redemptions appear here as customers use the code.');
    }
}
