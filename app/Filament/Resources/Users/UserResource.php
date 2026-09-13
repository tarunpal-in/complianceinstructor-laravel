<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * CLAUDE.md §8 improvement #3 — "Filament navigation, resource pages and
 * individual actions all gate on permissions." Every can* method below checks a
 * named permission, never a role, so the four roles stay data rather than code.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|null|UnitEnum $navigationGroup = 'Learners';

    /**
     * Listed as Customers, the slot it occupies in the agreed sidebar. It still
     * lists every user; the role filter that narrows it, and the separate
     * Staff & Roles screen, arrive with P5.
     */
    protected static ?string $navigationLabel = 'Customers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * Feeds the topbar global search.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var User $record */
        return [
            'Email' => $record->email,
            'Roles' => $record->roles->pluck('name')->join(', ') ?: 'Customer',
        ];
    }

    /**
     * A customer, from the three directions support arrives from: what they
     * bought, what they subscribe to, and what they can actually watch.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\OrdersRelationManager::class,
            RelationManagers\MembershipsRelationManager::class,
            RelationManagers\AccessRelationManager::class,
        ];
    }

    /**
     * The list draws each account's roles and counts what they have bought.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing a query a row. The counts are aliased
     * once, here: adding ->counts() on the column as well would put the same
     * alias in the select twice, which MySQL rejects outright.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('roles')
            ->withCount(['orders', 'subscriptions', 'entitlements']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_user') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_user') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_user') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_user') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_user') ?? false;
    }
}
