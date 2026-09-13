<?php

declare(strict_types=1);

namespace App\Filament\Resources\Entitlements\Pages;

use App\Enums\EntitlementSource;
use App\Filament\Resources\Entitlements\EntitlementResource;
use App\Models\Entitlement;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEntitlements extends ListRecords
{
    protected static string $resource = EntitlementResource::class;

    /**
     * The questions support actually arrives with, in order.
     *
     * "Who can see what" first, because that is the one a customer is waiting
     * on. "Given back" and "Granted by staff" next, because those are the two
     * somebody has to justify later.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active access')
                ->modifyQueryUsing(self::onlyActive(...))
                ->badge(fn (): int => Entitlement::query()->active()->count()),

            'given_back' => Tab::make('Given back')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('revoked_at'))
                ->badgeColor('warning'),

            'comped' => Tab::make('Granted by staff')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('source_type', EntitlementSource::Manual))
                ->badge(fn (): int => Entitlement::query()
                    ->where('source_type', EntitlementSource::Manual)
                    ->count()),

            'expired' => Tab::make('Expired')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNull('revoked_at')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now())),

            'all' => Tab::make('All'),
        ];
    }

    /**
     * Named rather than inline: a tab is handed an untyped builder, where a
     * model scope is invisible to static analysis.
     *
     * @param  Builder<Entitlement>  $query
     */
    private static function onlyActive(Builder $query): void
    {
        $query->active();
    }
}
