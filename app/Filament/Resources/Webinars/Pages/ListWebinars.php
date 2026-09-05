<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\Pages;

use App\Enums\WebinarStatus;
use App\Filament\Resources\Webinars\WebinarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWebinars extends ListRecords
{
    protected static string $resource = WebinarResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * The client thinks in "what is coming up", not in status enum values, so
     * the tabs lead with Upcoming rather than with All.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'upcoming' => Tab::make('Upcoming')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', WebinarStatus::publicValues())
                    ->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '>=', now()))
                ->badge(fn (): int => WebinarResource::getEloquentQuery()
                    ->whereIn('status', WebinarStatus::publicValues())
                    ->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '>=', now())
                    ->count()),

            'drafts' => Tab::make('Drafts')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', WebinarStatus::Draft->value))
                ->badge(fn (): int => WebinarResource::getEloquentQuery()
                    ->where('status', WebinarStatus::Draft->value)
                    ->count()),

            'on_demand' => Tab::make('On demand')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', WebinarStatus::publicValues())
                    ->where(fn (Builder $inner): Builder => $inner
                        ->whereNull('scheduled_at')
                        ->orWhere('scheduled_at', '<', now()))),

            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', WebinarStatus::Archived->value)),

            'all' => Tab::make('All'),
        ];
    }
}
