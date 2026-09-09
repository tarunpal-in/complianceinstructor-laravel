<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * The client thinks in "what needs chasing" and "what came in", not in enum
     * values, so the tabs lead with the two that carry work.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => OrderResource::getEloquentQuery()->count()),

            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::Paid->value))
                ->badge(fn (): int => OrderResource::getEloquentQuery()
                    ->where('status', OrderStatus::Paid->value)
                    ->count()),

            // Money the business is waiting on — the tab Finance opens first.
            'pending' => Tab::make('Awaiting payment')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::Pending->value))
                ->badge(fn (): int => OrderResource::getEloquentQuery()
                    ->where('status', OrderStatus::Pending->value)
                    ->count())
                ->badgeColor('warning'),

            'refunded' => Tab::make('Refunded')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    OrderStatus::Refunded->value,
                    OrderStatus::PartiallyRefunded->value,
                ])),

            'problems' => Tab::make('Failed &amp; cancelled')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    OrderStatus::Failed->value,
                    OrderStatus::Cancelled->value,
                ])),
        ];
    }
}
