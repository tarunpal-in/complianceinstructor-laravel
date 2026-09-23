<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Filament\Resources\Pages\Tables\PagesTable;
use App\Models\Page;
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
 * Marketing pages — CLAUDE.md §2's second leverage decision, worth ~5 weeks.
 *
 * "The client edits every marketing page unaided, without a developer touching
 * Blade" is §7's exit criterion for P6, and this screen is where that is either
 * true or it is not. The predecessor needed a developer for every copy change,
 * because every section of every page was its own model with its own form.
 *
 * §14 #14 — never create a model per page section. If a future section seems to
 * need one, it needs a block type instead.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    /** The slot the sidebar placeholder held, so nothing reshuffles. */
    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return PageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PagesTable::configure($table);
    }

    /**
     * The list counts each page's blocks and how many are hidden.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing a query a row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('sections');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'slug'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Page $record */
        return [
            'Address' => $record->path(),
            'Status' => $record->derivedStatus()->label(),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_page') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_page') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_page') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_page') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_page') ?? false;
    }

    /** Undoing a delete needs no more authority than doing it. */
    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->can('delete_page') ?? false;
    }
}
