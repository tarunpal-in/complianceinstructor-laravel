<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments;

use App\Enums\CommentStatus;
use App\Filament\Resources\Comments\Schemas\CommentOverview;
use App\Filament\Resources\Comments\Tables\CommentsTable;
use App\Models\Comment;
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
 * Comment moderation.
 *
 * New scope, added 2026-09-23 at the client's request. Worth restating where
 * this sits: §13 cut the forum first for "highest moderation burden", and this
 * screen is a smaller version of that same daily job. The badge is deliberately
 * the count of what is waiting, because the failure mode of a comment system is
 * nobody looking at it for a fortnight.
 *
 * A register, not a CRUD screen. Nothing here writes a comment — readers do
 * that — and nothing edits one either: publishing somebody's words is one
 * thing, editing them and leaving their name on it is another.
 */
class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    /** Directly under the blog and its categories. */
    protected static ?int $navigationSort = 22;

    protected static ?string $navigationLabel = 'Comments';

    protected static ?string $modelLabel = 'comment';

    protected static ?string $recordTitleAttribute = 'author_name';

    public static function infolist(Schema $schema): Schema
    {
        return CommentOverview::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['post', 'author', 'moderatedBy', 'parent']);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComments::route('/'),
            'view' => Pages\ViewComment::route('/{record}'),
        ];
    }

    /**
     * How many are waiting.
     *
     * Shown only when there are any — a badge permanently reading 0 is
     * furniture, and this one is meant to be the thing that gets somebody to
     * open the screen.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Comment::query()->awaitingReview()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return CommentStatus::Pending->color();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Comments awaiting review';
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['author_name', 'author_email', 'body'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Comment $record */
        return [
            'On' => $record->post->title ?? 'Deleted article',
            'Status' => $record->status->label(),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_comment') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view_comment') ?? false;
    }

    /** Readers write comments. Staff reply through the Reply action. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Publishing somebody's words is one decision; rewriting them and leaving
     * their name attached is a different and worse one.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete_comment') ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->can('delete_comment') ?? false;
    }
}
