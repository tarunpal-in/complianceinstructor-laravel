<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff;

use App\Filament\Resources\Staff\Schemas\StaffForm;
use App\Filament\Resources\Staff\Tables\StaffTable;
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
 * Who can get into the admin — §8 improvement #3.
 *
 * Until this screen existed, staff were managed on the Customers list under a
 * "Staff" tab, by adding and removing roles on a user record. That made access
 * a side effect of holding a role: the only way to remove it was to strip the
 * roles, which also erased what somebody had, and deleting a leaver nulled
 * seven columns across six tables that record who issued a certificate,
 * authorised a refund or published a comment.
 *
 * Same table as Customers, opposite filter. The two are separate screens
 * because they answer different questions — "what has this person bought" and
 * "what may this person do" — and because the actions that belong on one would
 * be alarming on the other.
 */
class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|null|UnitEnum $navigationGroup = 'System';

    /** The slot the "Staff & Roles" placeholder held. */
    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return StaffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffTable::configure($table);
    }

    /**
     * Anybody holding a role, active or not.
     *
     * Deactivated staff keep their roles deliberately, so they stay on this
     * screen — a leaver you cannot see is a leaver whose access nobody
     * re-checks.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('roles')
            ->with(['roles', 'deactivatedBy']);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\InviteStaff::route('/invite'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }

    /**
     * Staff management rides on the `role` subject.
     *
     * One permission for both screens on purpose: somebody who can change what
     * a role may do, and somebody who can give that role to a person, hold the
     * same power by two routes.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_role') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_role') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_role') ?? false;
    }

    /**
     * Never. Deactivation is how somebody leaves — see the migration for the
     * seven columns that would otherwise be orphaned.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
