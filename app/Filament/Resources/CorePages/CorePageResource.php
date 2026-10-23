<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorePages;

use App\Filament\Resources\CorePages\Schemas\CorePageForm;
use App\Filament\Resources\CorePages\Tables\CorePagesTable;
use App\Models\CorePage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The fixed "core" marketing pages — CLAUDE.md §6.
 *
 * Speakers, Membership, Industries and Contact keep their custom, data-driven
 * bodies; this screen edits the part that used to be hardcoded — hero, copy
 * blocks, the membership CTA and SEO. A fixed set: the four rows are seeded and
 * edited here, never created or deleted, so the resource has no create page and
 * `canCreate`/`canDelete` are closed to everyone.
 */
class CorePageResource extends Resource
{
    protected static ?string $model = CorePage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    /** Just after Pages (10), so the two page editors sit together. */
    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Core Pages';

    protected static ?string $recordTitleAttribute = 'hero_title';

    public static function form(Schema $schema): Schema
    {
        return CorePageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CorePagesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['sections', 'seo', 'heroMedia']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCorePages::route('/'),
            'edit' => Pages\EditCorePage::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_core_page') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_core_page') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_core_page') ?? false;
    }

    /** Fixed set — the four pages are seeded, never made or removed from the UI. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
