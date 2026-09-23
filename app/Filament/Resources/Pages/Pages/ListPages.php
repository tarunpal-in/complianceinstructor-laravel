<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Enums\PageStatus;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * Live, waiting and not finished — the three states the client thinks in.
     *
     * Scheduled is its own tab rather than a shade of published, because a page
     * sitting behind a date somebody typed wrongly is invisible in either of
     * the others.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'live' => Tab::make('Live')
                ->modifyQueryUsing(self::onlyLive(...))
                ->badge(fn (): int => Page::query()->live()->count()),

            'scheduled' => Tab::make('Scheduled')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', PageStatus::Published)
                    ->whereNotNull('published_at')
                    ->where('published_at', '>', now()))
                ->badgeColor('warning'),

            'draft' => Tab::make('Drafts')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', PageStatus::Draft))
                ->badge(fn (): int => Page::query()->where('status', PageStatus::Draft)->count()),

            'empty' => Tab::make('Needs content')
                // A page with nothing visible on it cannot be published, and
                // this is where the client finds the ones they started.
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereDoesntHave('sections', fn (Builder $section): Builder => $section->where('is_visible', true)))
                ->badgeColor('danger'),

            'all' => Tab::make('All'),
        ];
    }

    /**
     * Named rather than inline: a tab is handed an untyped builder, where a
     * model scope is invisible to static analysis.
     *
     * @param  Builder<Page>  $query
     */
    private static function onlyLive(Builder $query): void
    {
        $query->live();
    }
}
