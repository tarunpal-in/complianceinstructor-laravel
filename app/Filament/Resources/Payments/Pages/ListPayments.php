<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    /**
     * Tabs by what someone came here to do, not by enum value.
     *
     * Failed sits third because it is the one that needs chasing: a decline the
     * customer never noticed is a sale nobody is following up.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => PaymentResource::getEloquentQuery()->count()),

            'succeeded' => Tab::make('Captured')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', PaymentStatus::Succeeded->value)),

            'failed' => Tab::make('Failed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', PaymentStatus::Failed->value))
                ->badge(fn (): int => PaymentResource::getEloquentQuery()
                    ->where('status', PaymentStatus::Failed->value)
                    ->count())
                ->badgeColor('danger'),

            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', PaymentStatus::Pending->value))
                ->badge(fn (): int => PaymentResource::getEloquentQuery()
                    ->where('status', PaymentStatus::Pending->value)
                    ->count())
                ->badgeColor('warning'),

            // The invoiced ones. They have no gateway behind them, so they are
            // the set worth being able to look at on their own (§1 — this
            // audience is frequently invoiced rather than paying by card).
            'manual' => Tab::make('Invoiced')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('gateway', PaymentGateway::Manual->value)),

            'refunded' => Tab::make('Refunded')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->has('refunds')),
        ];
    }
}
