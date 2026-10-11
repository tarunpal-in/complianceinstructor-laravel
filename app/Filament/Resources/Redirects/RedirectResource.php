<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects;

use App\Filament\Resources\Redirects\Schemas\RedirectForm;
use App\Filament\Resources\Redirects\Tables\RedirectsTable;
use App\Models\Redirect;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The 301/302 register — client SEO brief §4.
 *
 * Where the client moves a URL by hand and where migration redirects land. The
 * form refuses a chain, a loop, or a source that would hijack a live page, so
 * "manage redirects without a developer" does not mean "break the site without a
 * developer" — the brief's avoid-list is enforced at the point of entry.
 *
 * Under Content, on the `redirect` permissions §8 already reserves. The Content
 * Editor owns it: moving a URL is part of running the site's content.
 */
class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    /** After the content it redirects between. */
    protected static ?int $navigationSort = 45;

    protected static ?string $navigationLabel = 'Redirects';

    protected static ?string $modelLabel = 'redirect';

    protected static ?string $recordTitleAttribute = 'from_path';

    public static function form(Schema $schema): Schema
    {
        return RedirectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RedirectsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRedirects::route('/'),
            'create' => Pages\CreateRedirect::route('/create'),
            'edit' => Pages\EditRedirect::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_redirect') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_redirect') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_redirect') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_redirect') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_redirect') ?? false;
    }
}
