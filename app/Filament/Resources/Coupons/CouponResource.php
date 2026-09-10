<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons;

use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Coupons\Pages\EditCoupon;
use App\Filament\Resources\Coupons\Pages\ListCoupons;
use App\Filament\Resources\Coupons\Schemas\CouponForm;
use App\Filament\Resources\Coupons\Tables\CouponsTable;
use App\Models\Coupon;
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
 * Coupons — CLAUDE.md §6, with the caps from §8 improvement #2.
 *
 * §14 #3 governs everything downstream of this screen: a code is validated on
 * the server and the table is never serialised into a page. The predecessor
 * embedded `Coupon::all()` in the checkout HTML and checked the code in
 * JavaScript, so every discount was readable in the page source and none of
 * them were enforced.
 */
class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|null|UnitEnum $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return CouponForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CouponsTable::configure($table);
    }

    /**
     * The list counts redemptions per row and resolves each scope.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing two queries a row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('scopeable')
            ->withCount('redemptions');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RelationManagers\RedemptionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
            'edit' => EditCoupon::route('/{record}/edit'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'description'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Coupon $record */
        return [
            'Discount' => $record->describeDiscount(),
            'Applies to' => $record->describeScope(),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_coupon') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_coupon') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_coupon') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_coupon') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_coupon') ?? false;
    }
}
