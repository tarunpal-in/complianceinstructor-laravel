<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderOverview;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Orders — CLAUDE.md §6.
 *
 * There is no create or edit page, which is deliberate. An order is the record
 * of money changing hands: it is created by checkout inside one transaction
 * (§4 rule 2) and amended only through the Refund and Record-payment actions,
 * which are separately permissioned and audited. A general-purpose edit form
 * over `total_cents` is how the predecessor's admin came to disagree with its
 * own payment records.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|null|UnitEnum $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return OrderOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    /**
     * The list draws a customer, a line count and the gateway per row, and the
     * reconciliation filter walks payments and refunds.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing a query per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['user', 'payments'])
            ->withCount('items');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
            RelationManagers\AttendeesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Order $record */
        return [
            'Customer' => $record->customerName(),
            'Status' => $record->status->label(),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_order') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_order') ?? false;
    }

    /** Checkout creates orders, not people. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_order') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_order') ?? false;
    }
}
