<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostCategories;

use App\Filament\Resources\PostCategories\Schemas\PostCategoryForm;
use App\Filament\Resources\PostCategories\Tables\PostCategoriesTable;
use App\Models\PostCategory;
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
 * Blog categories — CLAUDE.md §5.7.
 *
 * Gated on the post permissions rather than getting its own set. A category is
 * part of the blog, not a thing in its own right, and §8's ~40 named
 * permissions are meant to be the ones somebody would actually grant
 * separately — nobody hands out "edit blog categories but not blog posts".
 */
class PostCategoryResource extends Resource
{
    protected static ?string $model = PostCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    /** Directly under Blog, which is where somebody goes looking for it. */
    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Blog categories';

    protected static ?string $modelLabel = 'category';

    protected static ?string $pluralModelLabel = 'blog categories';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PostCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PostCategoriesTable::configure($table);
    }

    /** The list counts each category's articles. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->withCount('posts');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPostCategories::route('/'),
            'create' => Pages\CreatePostCategory::route('/create'),
            'edit' => Pages\EditPostCategory::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_post') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_post') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_post') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update_post') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_post') ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->can('delete_post') ?? false;
    }
}
