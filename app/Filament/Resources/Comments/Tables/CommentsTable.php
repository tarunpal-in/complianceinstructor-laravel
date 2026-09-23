<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments\Tables;

use App\Actions\Comments\ModerateComment;
use App\Actions\Comments\ReplyAsStaff;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * The moderation queue.
 *
 * Built to be read down a screen rather than across one: the comment itself is
 * the widest column and wraps, because a moderator decides by reading the
 * words, and a list that truncates them to one line makes every decision a
 * click away. The plans list taught this lesson the expensive way.
 */
class CommentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('author_name')
                    ->label('From')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Comment $record): string => $record->author_email)
                    ->badge(fn (Comment $record): bool => $record->isFromStaff())
                    ->color(fn (Comment $record): string => $record->isFromStaff() ? 'info' : 'gray')
                    ->wrap()
                    ->width('14rem'),

                TextColumn::make('body')
                    ->label('Comment')
                    ->searchable()
                    ->state(fn (Comment $record): string => $record->preview(240))
                    // The filter's guess sits under the words it is guessing
                    // about, so overruling it does not need a second screen.
                    ->description(fn (Comment $record): ?string => $record->spam_reason)
                    ->wrap()
                    ->width('30rem'),

                TextColumn::make('post.title')
                    ->label('On')
                    ->placeholder('Deleted article')
                    ->url(fn (Comment $record): ?string => $record->post?->viewableUrl())
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->description(fn (Comment $record): ?string => $record->parent_id === null
                        ? null
                        : 'In reply to '.($record->parent->author_name ?? 'a deleted comment'))
                    ->wrap()
                    ->width('16rem')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Comment $record): string => $record->status->label())
                    ->color(fn (Comment $record): string => $record->status->color())
                    ->description(fn (Comment $record): ?string => $record->moderatedBy?->name)
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Left')
                    ->dateTime('j M Y, H:i')
                    ->description(fn (Comment $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable()
                    ->width('11rem'),
            ])
            // Oldest first: the queue is a queue, and somebody who commented on
            // Monday should not still be waiting because Friday's arrived.
            ->defaultSort('created_at', 'asc')
            ->filters([
                SelectFilter::make('post_id')
                    ->label('Article')
                    ->searchable()
                    ->options(fn (): array => Post::query()
                        ->whereHas('comments')
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all()),

                /*
                 * No status filter — the tabs are the status, and a filter
                 * doing a tab's job narrows every other tab on top of its own
                 * query, which is how the entitlements screen once had a tab
                 * that could never show anything.
                 */
                TrashedFilter::make()->label('Deleted comments'),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                self::approveAction(),
                self::replyAction(),
                self::spamAction(),
                self::rejectAction(),
                ViewAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkModerateAction('approveAll', CommentStatus::Approved, 'Publish these', 'success', Heroicon::OutlinedCheckCircle),
                    self::bulkModerateAction('spamAll', CommentStatus::Spam, 'Mark as spam', 'danger', Heroicon::OutlinedNoSymbol),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Comments appear here as readers leave them. Nothing reaches the site until it is published from this screen.');
    }

    // Single decisions -----------------------------------------------------------

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Publish')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Comment $record): bool => ! $record->isApproved() && self::canModerate())
            ->requiresConfirmation()
            ->modalHeading('Publish this comment?')
            ->modalDescription('It appears under the article immediately, with the name and date shown here.')
            ->action(fn (Comment $record) => self::decide($record, CommentStatus::Approved, 'Published'));
    }

    private static function spamAction(): Action
    {
        return Action::make('spam')
            ->label('Spam')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Comment $record): bool => $record->status !== CommentStatus::Spam && self::canModerate())
            ->requiresConfirmation()
            ->modalHeading('Mark as spam?')
            ->modalDescription('It comes off the article. Kept rather than deleted, so the same sender can be recognised next time.')
            ->action(fn (Comment $record) => self::decide($record, CommentStatus::Spam, 'Marked as spam'));
    }

    /**
     * Not spam — just not going up.
     *
     * The distinction the enum keeps: a robot wrote it, versus a person was
     * rude. Deleting the second loses the record of a decision somebody may
     * later be asked about.
     */
    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (Comment $record): bool => $record->status !== CommentStatus::Rejected && self::canModerate())
            ->requiresConfirmation()
            ->modalHeading('Reject this comment?')
            ->modalDescription('It stays off the article without being counted as spam.')
            ->action(fn (Comment $record) => self::decide($record, CommentStatus::Rejected, 'Rejected'));
    }

    /**
     * Answer in public, under the site's own name.
     *
     * Publishing straight away: it is the client's blog, and queueing their
     * reply for their own approval is a loop with nobody outside it.
     */
    private static function replyAction(): Action
    {
        return Action::make('reply')
            ->label('Reply')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('primary')
            ->visible(fn (Comment $record): bool => $record->isApproved()
                && $record->parent_id === null
                && self::canModerate())
            ->modalHeading('Reply to this comment')
            ->modalDescription(fn (Comment $record): string => $record->author_name.' wrote: '.$record->preview(160))
            ->schema([
                Textarea::make('body')
                    ->label('Your reply')
                    ->required()
                    ->rows(4)
                    ->maxLength(2000)
                    ->helperText('Published immediately, under your own name.'),
            ])
            ->action(function (Comment $record, array $data): void {
                $author = auth()->user();

                if (! $author instanceof User) {
                    return;
                }

                try {
                    app(ReplyAsStaff::class)($record, $author, (string) $data['body']);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Not posted')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Reply posted')->send();
            });
    }

    // Several at once ---------------------------------------------------------------

    /**
     * The same decision over a selection.
     *
     * The queue arrives in runs — a morning of spam, or a dozen genuine remarks
     * on one popular article — and making somebody confirm each one is how a
     * moderation screen stops being opened.
     */
    private static function bulkModerateAction(
        string $name,
        CommentStatus $status,
        string $label,
        string $color,
        Heroicon $icon,
    ): BulkAction {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(self::canModerate(...))
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($status, $label): void {
                $moderate = app(ModerateComment::class);
                $changed = 0;

                /** @var Comment $record */
                foreach ($records as $record) {
                    if ($record->status === $status) {
                        continue;
                    }

                    $moderate($record, $status);
                    $changed++;
                }

                Notification::make()
                    ->success()
                    ->title($label)
                    ->body(trans_choice(':count comment|:count comments', $changed, ['count' => $changed]).' updated.')
                    ->send();
            });
    }

    // Shared ---------------------------------------------------------------------------

    private static function decide(Comment $record, CommentStatus $status, string $title): void
    {
        app(ModerateComment::class)($record, $status);

        Notification::make()->success()->title($title)->send();
    }

    private static function canModerate(): bool
    {
        return auth()->user()?->can('moderate_comment') ?? false;
    }
}
