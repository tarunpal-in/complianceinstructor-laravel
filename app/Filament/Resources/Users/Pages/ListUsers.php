<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * The screen is called Customers, so customers is what it opens on.
     *
     * It listed every account before this, administrators included, which is
     * why the Super Admin turned up among the people who buy things. §5.8 gives
     * users no role column — "customer" is the *absence* of the four §8 admin
     * roles — so the split is a query rather than a column, and the first tab
     * is the one the sidebar label promises.
     *
     * Staff are a tab rather than hidden: the Staff & Roles screen is still a
     * placeholder until P5, and filtering administrators out of the only screen
     * that lists them would leave nobody able to find them.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'customers' => Tab::make('Customers')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereDoesntHave('roles'))
                ->badge(fn (): int => User::query()->whereDoesntHave('roles')->count()),

            /*
             * The Staff tab used to sit here. It moved to its own screen under
             * System when staff became something with a lifecycle rather than
             * a filter — two screens listing the same people is two places to
             * change them, and the actions that belong on one (deactivate,
             * reset two-factor) would be alarming on the other.
             */
            'unverified' => Tab::make('Unverified')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('email_verified_at'))
                ->badge(fn (): int => User::query()->whereNull('email_verified_at')->count())
                ->badgeColor('danger'),

            'all' => Tab::make('Everyone'),
        ];
    }
}
