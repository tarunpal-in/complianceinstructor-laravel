<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Enums\SubscriptionState;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    /**
     * The client thinks in "who is paying", "who is leaving" and "what is
     * broken", not in Stripe status values.
     *
     * Each tab goes through the model's own state scope, which asks Cashier —
     * so a tab can never disagree with what the billing side believes about the
     * same subscription.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(self::onlyActive(...))
                ->badge(fn (): int => self::countIn(SubscriptionState::Active)),

            // Cancelled, still inside the period they paid for. The tab worth
            // having: these are the people still reachable.
            'ending' => Tab::make('Ending')
                ->modifyQueryUsing(self::onlyEnding(...))
                ->badge(fn (): int => self::countIn(SubscriptionState::Ending))
                ->badgeColor('warning'),

            'problems' => Tab::make('Needs attention')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('stripe_status', self::troubledStatuses()))
                ->badge(fn (): int => SubscriptionResource::getEloquentQuery()
                    ->whereIn('stripe_status', self::troubledStatuses())
                    ->count())
                ->badgeColor('danger'),

            'ended' => Tab::make('Ended')
                ->modifyQueryUsing(self::onlyEnded(...)),

            'all' => Tab::make('All'),
        ];
    }

    /**
     * A tab's query, narrowed to one state.
     *
     * Every tab goes through the model's own state scope rather than calling
     * Cashier's scopes here, so the tabs and the status filter cannot drift
     * apart. Named methods rather than inline closures because a tab is handed
     * an untyped builder, where a model scope is invisible to static analysis.
     *
     * @param  Builder<Subscription>  $query
     */
    private static function onlyActive(Builder $query): void
    {
        $query->inState(SubscriptionState::Active);
    }

    /** @param  Builder<Subscription>  $query */
    private static function onlyEnding(Builder $query): void
    {
        $query->inState(SubscriptionState::Ending);
    }

    /** @param  Builder<Subscription>  $query */
    private static function onlyEnded(Builder $query): void
    {
        $query->inState(SubscriptionState::Ended);
    }

    /**
     * A badge count.
     *
     * Straight off the model rather than through the resource query, which
     * eager-loads three relations nobody counts.
     */
    private static function countIn(SubscriptionState $state): int
    {
        return Subscription::query()->inState($state)->count();
    }

    /**
     * The states where somebody has to do something — a failed payment, an
     * abandoned signup.
     *
     * @return array<int, string>
     */
    private static function troubledStatuses(): array
    {
        return array_values(array_map(
            static fn (SubscriptionState $state): string => $state->value,
            array_filter(
                SubscriptionState::cases(),
                static fn (SubscriptionState $state): bool => $state->needsAttention(),
            ),
        ));
    }
}
