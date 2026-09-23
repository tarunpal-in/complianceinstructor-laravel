<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Pages;

use App\Enums\PublishStatus;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Write an article')];
    }

    /**
     * Out, waiting, and being written — how somebody running a blog thinks.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'published' => Tab::make('Published')
                ->modifyQueryUsing(self::onlyLive(...))
                ->badge(fn (): int => Post::query()->live()->count()),

            'scheduled' => Tab::make('Scheduled')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', PublishStatus::Published)
                    ->whereNotNull('published_at')
                    ->where('published_at', '>', now()))
                ->badgeColor('warning'),

            'draft' => Tab::make('Drafts')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PublishStatus::Draft))
                ->badge(fn (): int => Post::query()->where('status', PublishStatus::Draft)->count()),

            // Nothing on the site links to an uncategorised article, so these
            // are effectively invisible once published. Worth their own tab.
            'uncategorised' => Tab::make('Uncategorised')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('post_category_id'))
                ->badgeColor('danger'),

            'all' => Tab::make('All'),
        ];
    }

    /**
     * Named rather than inline: a tab is handed an untyped builder, where a
     * model scope is invisible to static analysis.
     *
     * @param  Builder<Post>  $query
     */
    private static function onlyLive(Builder $query): void
    {
        $query->live();
    }
}
