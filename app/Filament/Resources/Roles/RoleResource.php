<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Tables\RolesTable;
use App\Models\Role;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * What each role may do — §8 improvement #3.
 *
 * The four roles §8 names are marked `is_system` and shown read-only: their
 * permissions are owned by RolePermissionSeeder, which uses syncPermissions(),
 * so anything edited here would be silently reverted on the next deploy. Two
 * sources of truth disagreeing is the failure this project has avoided
 * everywhere else, and a screen that appears to save and does not is a worse
 * version of it.
 *
 * They are still shown in full rather than hidden, because "why can Sarah not
 * see refunds" is half the reason this screen exists and the answer is on the
 * Finance role.
 *
 * Roles the client creates are theirs entirely and the seeder never touches
 * them.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|null|UnitEnum $navigationGroup = 'System';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'role';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['users', 'permissions'])
            ->orderByDesc('is_system')
            ->orderBy('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_role') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_role') ?? false;
    }

    /**
     * A system role opens, and refuses to save — see RoleForm.
     *
     * Blocking the page entirely would hide the permissions, which is the one
     * thing somebody comes to this screen to read.
     */
    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_role') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        /** @var Role $record */
        return ($auth = auth()->user())?->can('delete_role') === true && $record->isDeletable();
    }
}
