<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments\Pages;

use App\Enums\CommentStatus;
use App\Filament\Resources\Comments\CommentResource;
use App\Models\Comment;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListComments extends ListRecords
{
    protected static string $resource = CommentResource::class;

    /**
     * The job, then the results, then the bin — how somebody moderating thinks.
     *
     * Waiting comes first and is the default, because a comment system fails by
     * nobody looking at the queue rather than by anything going wrong with it.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Waiting')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', CommentStatus::Pending))
                ->badge(fn (): int => self::countOf(CommentStatus::Pending))
                ->badgeColor(CommentStatus::Pending->color()),

            'approved' => Tab::make('Published')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', CommentStatus::Approved))
                ->badge(fn (): int => self::countOf(CommentStatus::Approved))
                ->badgeColor(CommentStatus::Approved->color()),

            'spam' => Tab::make('Spam')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', CommentStatus::Spam))
                ->badge(fn (): int => self::countOf(CommentStatus::Spam))
                ->badgeColor(CommentStatus::Spam->color()),

            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', CommentStatus::Rejected)),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'pending';
    }

    /** Counted, never stored — a tally column drifts the first time a row moves. */
    private static function countOf(CommentStatus $status): int
    {
        return Comment::query()->where('status', $status)->count();
    }
}
