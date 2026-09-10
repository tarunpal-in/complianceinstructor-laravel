<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Pages;

use App\Filament\Resources\Coupons\CouponResource;
use App\Models\Coupon;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCoupons extends ListRecords
{
    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * The client asks "what is running", not "what is active" — a code can be
     * switched on and still be dead because it expired or ran out.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'live' => Tab::make('Running')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('id', Coupon::usableIds()))
                ->badge(fn (): int => Coupon::query()->usable()->count()),

            'scheduled' => Tab::make('Scheduled')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereNotNull('starts_at')
                    ->where('starts_at', '>', now())),

            'finished' => Tab::make('Finished')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<', now())),

            'off' => Tab::make('Switched off')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),

            'all' => Tab::make('All'),
        ];
    }
}
