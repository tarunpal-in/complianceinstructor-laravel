<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars;

use App\Filament\Resources\Webinars\Pages\CreateWebinar;
use App\Filament\Resources\Webinars\Pages\EditWebinar;
use App\Filament\Resources\Webinars\Pages\ListWebinars;
use App\Filament\Resources\Webinars\Schemas\WebinarForm;
use App\Filament\Resources\Webinars\Tables\WebinarsTable;
use App\Models\Webinar;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class WebinarResource extends Resource
{
    protected static ?string $model = Webinar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static string|null|UnitEnum $navigationGroup = 'Catalogue';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return WebinarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebinarsTable::configure($table);
    }

    /**
     * Speaker and industry are eager-loaded, and the two counts computed in the
     * query. Model::shouldBeStrict() turns a lazy load into an exception, so a
     * missing with() fails loudly rather than costing 75 queries a page.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['speaker', 'industry'])
            ->withCount(['offerings', 'accreditations']);
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'summary'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Webinar $record */
        return [
            'Speaker' => (string) $record->speaker?->name,
            'Status' => $record->status->label(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebinars::route('/'),
            'create' => CreateWebinar::route('/create'),
            'edit' => EditWebinar::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_webinar') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_webinar') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_webinar') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_webinar') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_webinar') ?? false;
    }
}
