<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipPlans\Pages;

use App\Filament\Resources\MembershipPlans\MembershipPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMembershipPlans extends ListRecords
{
    protected static string $resource = MembershipPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * On sale, drafted, retired — the three states the client actually thinks
     * in. A retired plan is kept rather than deleted because people are still
     * subscribed to it.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'live' => Tab::make('On sale')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true)),

            'draft' => Tab::make('Not on sale')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),

            'all' => Tab::make('All'),
        ];
    }
}
