<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media;

use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Media\Schemas\MediaForm;
use App\Filament\Resources\Media\Tables\MediaTable;
use App\Models\Media;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The media library — docs/media-manager.md.
 *
 * One screen over spatie's `media` table, holding both library files (owned by a
 * folder, reusable) and files attached to a webinar, speaker or industry (shown
 * read-only, so storage totals and "which speaker has no portrait" still work).
 *
 * There is no create or edit page: a media library is a browser with modals, not
 * a wizard. Everything happens on the list.
 */
class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 30;

    protected static ?string $label = 'media';

    protected static ?string $pluralLabel = 'Media';

    public static function form(Schema $schema): Schema
    {
        return MediaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MediaTable::configure($table);
    }

    /**
     * `model` is the folder or the owning record, drawn on every row; `document`
     * carries the page count; `usages` decides whether delete is allowed.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing sixty queries on a sixty-card grid.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['model', 'document', 'tags'])
            ->withCount('usages');
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'file_name'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Media $record */
        return [
            'Type' => $record->kind()->label(),
            'Size' => $record->readableSize(),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListMedia::route('/')];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_media') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_media') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_media') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_media') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_media') ?? false;
    }
}
