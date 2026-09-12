<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Enums\SubscriptionState;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 * Widths and wrapping are set deliberately. A table cell is
                 * whitespace-nowrap by default, so an email address and a plan
                 * description left to themselves push the table past its
                 * container and squeeze every other column into a sliver.
                 */
                TextColumn::make('member.name')
                    ->label('Member')
                    ->searchable()
                    ->weight('bold')
                    // `->` rather than `?->`: null coalescing already covers a
                    // member whose account was removed, and `?->` before `??`
                    // is redundant.
                    ->description(fn (Subscription $record): string => $record->member->email ?? 'Account deleted')
                    ->wrap()
                    ->width('18rem'),

                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->placeholder('No plan')
                    ->description(fn (Subscription $record): string => $record->describePrice())
                    ->sortable()
                    ->width('10rem'),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Subscription $record): string => $record->state()->label())
                    ->color(fn (Subscription $record): string => $record->state()->color())
                    // A grace period is the case this column exists to make
                    // visible: cancelled, but still paid up.
                    ->description(fn (Subscription $record): ?string => $record->describeAccessEnd())
                    ->width('9rem'),

                /*
                 * What this member is owed — their snapshot, not the plan's
                 * current terms. That distinction is the whole point of
                 * subscription_entitlements, and this is where it becomes
                 * visible to support.
                 */
                TextColumn::make('allowances')
                    ->label('Grants')
                    ->state(fn (Subscription $record): array => $record->allowanceLines())
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('No allowances recorded')
                    ->size('sm')
                    ->width('15rem'),

                TextColumn::make('terms')
                    ->label('Terms')
                    ->badge()
                    ->state(fn (Subscription $record): string => $record->termsDifferFromPlan() ? 'Differ' : 'As sold')
                    ->color(fn (Subscription $record): string => $record->termsDifferFromPlan() ? 'warning' : 'gray')
                    ->tooltip(fn (Subscription $record): ?string => $record->termsDifferFromPlan()
                        ? 'The plan has been edited since this member joined. They keep what they signed up under.'
                        : null)
                    ->toggleable(),

                /*
                 * The question this screen is asked most: from when, until
                 * when. Both dates in one column because they are read
                 * together — a start with no end is only half an answer, and it
                 * is the half the client already had.
                 */
                TextColumn::make('term')
                    ->label('Membership runs')
                    ->state(fn (Subscription $record): string => $record->describeTermDates())
                    ->description(fn (Subscription $record): ?string => self::remaining($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('term_starts_at', $direction))
                    ->wrap()
                    ->width('11rem'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(SubscriptionState::options())
                    // Through the model scope, which asks Cashier — so this list
                    // cannot disagree with what the billing side believes.
                    ->query(self::narrowToState(...)),

                SelectFilter::make('membership_plan_id')
                    ->label('Plan')
                    ->options(fn (): array => MembershipPlan::query()
                        ->withoutGlobalScopes()
                        ->orderBy('sort_order')
                        ->pluck('name', 'id')
                        ->all()),

                /*
                 * Support's question, and not answerable from any column: whose
                 * terms no longer match the plan they are on. Compared in the
                 * database rather than in PHP so it can be paginated.
                 */
                Filter::make('terms_differ')
                    ->label('Terms differ from the plan')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn('id', Subscription::idsWithAlteredTerms())),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([ViewAction::make()])
            // No bulk actions: everything a subscription can have done to it
            // moves money or access for one named person, and none of it should
            // ever be done to forty rows at once by accident.
            ->toolbarActions([])
            ->emptyStateHeading('No subscriptions yet')
            ->emptyStateDescription('Memberships appear here as customers subscribe. They are created by checkout, never by hand.');
    }

    /**
     * "3 months left", or nothing where there is no end to count towards.
     *
     * Said in days only when it is close enough for the number of days to be
     * the thing that matters — "87 days left" is arithmetic nobody asked for,
     * and "3 days left" is the one worth noticing.
     */
    private static function remaining(Subscription $record): ?string
    {
        $days = $record->daysRemaining();

        return match (true) {
            $days === null => null,
            $days === 0 => 'ends today',
            $days <= 14 => $days.' '.str('day')->plural($days).' left',
            default => 'ends '.$record->accessEndsAt()?->diffForHumans(),
        };
    }

    /**
     * The status filter, narrowed through the model's own state scope.
     *
     * Named rather than written inline because a filter closure is handed an
     * untyped builder, where a model scope is invisible to static analysis.
     *
     * @param  Builder<Subscription>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<Subscription>
     */
    private static function narrowToState(Builder $query, array $data): Builder
    {
        $state = SubscriptionState::tryFrom((string) ($data['value'] ?? ''));

        return $state === null ? $query : $query->inState($state);
    }
}
