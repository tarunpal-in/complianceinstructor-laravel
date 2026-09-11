<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipPlans;

use App\Filament\Resources\MembershipPlans\Pages\CreateMembershipPlan;
use App\Filament\Resources\MembershipPlans\Pages\EditMembershipPlan;
use App\Filament\Resources\MembershipPlans\Pages\ListMembershipPlans;
use App\Filament\Resources\MembershipPlans\Schemas\MembershipPlanForm;
use App\Filament\Resources\MembershipPlans\Tables\MembershipPlansTable;
use App\Models\MembershipPlan;
use App\Models\Subscription;
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
 * Membership plans — CLAUDE.md §6 "Plan builder, entitlement rows, subscriber
 * list", over the §5.5 schema.
 *
 * The plan a subscriber is on is a template they were sold from, not a live
 * contract: their allowances were copied to `subscription_entitlements` at
 * signup and nothing downstream reads this record for them again. That is what
 * makes this screen safe to edit — agreed with Tarun on 2026-09-11, so that an
 * active subscriber keeps the terms they signed up under.
 */
class MembershipPlanResource extends Resource
{
    protected static ?string $model = MembershipPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|null|UnitEnum $navigationGroup = 'Memberships';

    protected static ?string $recordTitleAttribute = 'name';

    /** The slot the sidebar placeholder held, so nothing reshuffles. */
    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'membership plan';

    public static function form(Schema $schema): Schema
    {
        return MembershipPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembershipPlansTable::configure($table);
    }

    /**
     * The list summarises allowances per row and counts members.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing a query a row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('entitlements')
            // Cashier's own definition of active, so the count cannot disagree
            // with what the billing side believes. Aliased once, here: adding
            // ->counts() on the column too would put the alias in the select
            // twice, which MySQL rejects.
            ->withCount(['subscriptions as active_subscribers_count' => self::onlyActive(...)]);
    }

    /**
     * Cashier's `active` scope, named so the builder's model is known.
     *
     * @param  Builder<Subscription>  $query
     */
    private static function onlyActive(Builder $query): void
    {
        $query->active();
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RelationManagers\SubscribersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembershipPlans::route('/'),
            'create' => CreateMembershipPlan::route('/create'),
            'edit' => EditMembershipPlan::route('/{record}/edit'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug', 'description'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var MembershipPlan $record */
        return [
            'Price' => $record->describePrice(),
            'Grants' => $record->describeAllowances(),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_membership_plan') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_membership_plan') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_membership_plan') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_membership_plan') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_membership_plan') ?? false;
    }
}
