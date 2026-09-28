<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Support\OrderCsv;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * Two exports, because "the orders" means two different files.
     *
     * Order-level reconciles against a bank statement, one row per order;
     * line-level has the webinar, format, speaker and industry on every row and
     * is useless for bookkeeping because the totals repeat. People ask for "the
     * orders" meaning one or the other, and handing over the wrong one wastes a
     * day at month end — so both are offered by name.
     *
     * They sit here rather than under Reports on purpose: exports belong where
     * the data already is. Somebody looking at a filtered list of last month's
     * paid orders should not have to navigate somewhere else to export exactly
     * that — and `getFilteredTableQuery` means the file matches the screen,
     * tab and filters included.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportOrders')
                ->label('Export orders')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(self::canExport(...))
                ->action(fn (): StreamedResponse => OrderCsv::downloadOrders($this->getFilteredTableQuery())),

            Action::make('exportLines')
                ->label('Export order lines')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(self::canExport(...))
                ->action(fn (): StreamedResponse => OrderCsv::downloadLines($this->getFilteredTableQuery())),
        ];
    }

    /**
     * Reading an order and walking away with every order are different
     * privileges, which is why §8 names `export_data` separately.
     */
    private static function canExport(): bool
    {
        return auth()->user()?->can('export_data') ?? false;
    }

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
