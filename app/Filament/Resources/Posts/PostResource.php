<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts;

use App\Filament\Resources\Posts\Schemas\PostForm;
use App\Filament\Resources\Posts\Tables\PostsTable;
use App\Models\Post;
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
 * The blog — CLAUDE.md §5.7 and §6.
 *
 * §13 has this sixth on the descope ladder: "useful for SEO, but nothing
 * depends on it". That is the right risk assessment and also the reason to
 * build it plainly — §3 puts the business on server-rendered SEO, and the blog
 * is the only part of the site that produces indexable pages without anybody
 * scheduling a webinar. One week in §6, which holds only if this stays an
 * editor with some fields rather than a second page builder.
 */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    /** The slot the sidebar placeholder held, so nothing reshuffles. */
    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Blog';

    protected static ?string $modelLabel = 'article';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return PostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PostsTable::configure($table);
    }

    /**
     * Rows draw a category, an author and a cover thumbnail.
     *
     * Model::shouldBeStrict() is on outside production, so a missing eager load
     * throws rather than quietly costing three queries a row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['category', 'author', 'media']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'slug', 'excerpt'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Post $record */
        return [
            'Category' => $record->category->name ?? 'Uncategorised',
            'Status' => $record->derivedStatus()->label(),
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

    /** Undoing a delete needs no more authority than doing it. */
    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->can('delete_post') ?? false;
    }
}
