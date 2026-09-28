<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The last ten orders.
 *
 * "What came in" is the other half of the morning question, and it is the one
 * thing on this dashboard nobody wants summarised — they want to see the
 * actual orders, because the interesting part is usually *who*.
 *
 * Read-only and unsorted by the reader: the whole value is that it is the most
 * recent ten. Somebody wanting to sort or filter wants the Orders screen, which
 * is one click away through any row.
 */
class RecentOrders extends TableWidget
{
    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view_any_order') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest orders')
            ->query(
                Order::query()
                    ->with('user')
                    ->whereNotNull('placed_at')
                    ->latest('placed_at')
                    ->limit(10),
            )
            // The limit above is the point, so the table must not paginate past
            // it or re-sort around it.
            ->paginated(false)
            ->defaultSort('placed_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Order')
                    ->weight('bold')
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),

                TextColumn::make('customer')
                    ->label('Customer')
                    ->state(fn (Order $record): string => $record->customerName())
                    ->description(fn (Order $record): string => $record->customerEmail())
                    ->wrap(),

                TextColumn::make('total_cents')
                    ->label('Total')
                    ->state(fn (Order $record): string => Money::format($record->total_cents, $record->currency))
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // Derived, matching the Orders screen: §14 #2 has status
                    // following the payments rather than a column, and a
                    // dashboard disagreeing with the list it links to is worse
                    // than no dashboard.
                    ->state(fn (Order $record): string => $record->derivedStatus()->label())
                    ->color(fn (Order $record): string => $record->derivedStatus()->color()),

                TextColumn::make('placed_at')
                    ->label('Placed')
                    ->dateTime('j M, H:i')
                    ->description(fn (Order $record): ?string => $record->placed_at?->diffForHumans()),
            ])
            ->emptyStateHeading('No orders yet')
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]));
    }

    /** @return Builder<Order> */
    protected function getTableQuery(): Builder
    {
        return Order::query()->with('user')->whereNotNull('placed_at')->latest('placed_at')->limit(10);
    }
}
