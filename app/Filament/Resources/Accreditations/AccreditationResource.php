<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accreditations;

use App\Filament\Resources\Accreditations\Pages\CreateAccreditation;
use App\Filament\Resources\Accreditations\Pages\EditAccreditation;
use App\Filament\Resources\Accreditations\Pages\ListAccreditations;
use App\Filament\Resources\Accreditations\Schemas\AccreditationForm;
use App\Filament\Resources\Accreditations\Tables\AccreditationsTable;
use App\Models\Accreditation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Reference data, set once and rarely touched — §12 has these bodies' rules
 * "prescribed and not negotiable". It sits in Catalogue for now; the agreed
 * sidebar puts config of this kind behind Settings, so it may move there.
 */
class AccreditationResource extends Resource
{
    protected static ?string $model = Accreditation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|null|UnitEnum $navigationGroup = 'Catalogue';

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return AccreditationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccreditationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('webinars');
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccreditations::route('/'),
            'create' => CreateAccreditation::route('/create'),
            'edit' => EditAccreditation::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_accreditation') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_accreditation') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_accreditation') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_accreditation') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_accreditation') ?? false;
    }
}
