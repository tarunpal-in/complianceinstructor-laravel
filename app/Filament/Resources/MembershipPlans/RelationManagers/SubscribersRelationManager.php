<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipPlans\RelationManagers;

use App\Models\Subscription;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is on this plan — §6, "Plan builder, entitlement rows, subscriber list".
 *
 * Read-only, and not because it was quicker. §3 gives Cashier every part of the
 * subscription lifecycle; cancelling or repricing one from here would write a
 * row Stripe knows nothing about, and the two would disagree from then on.
 *
 * The column worth having is **Grants**: it reads each subscriber's own
 * snapshot, so when the plan has been retuned since they joined, this screen
 * says what they are actually owed rather than what a new signup would get.
 */
class SubscribersRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'Subscribers';

    protected static ?string $modelLabel = 'subscriber';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /** Cashier owns these rows; nothing here may write one. */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('stripe_id')
            // `plan.entitlements` as well as the subscriber's own: the Grants
            // column compares the two, and Model::shouldBeStrict() turns a
            // missing eager load into an exception rather than a silent
            // query per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['member', 'entitlements', 'plan.entitlements']))
            ->columns([
                TextColumn::make('member.name')
                    ->label('Member')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Subscription $record): ?string => $record->member?->email),

                TextColumn::make('stripe_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Subscription $record): string => match (true) {
                        $record->onGracePeriod() => 'warning',
                        $record->active() => 'success',
                        $record->pastDue() => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Subscription $record): ?string => $record->onGracePeriod()
                        ? 'Ends '.$record->ends_at?->diffForHumans()
                        : null),

                TextColumn::make('price_cents')
                    ->label('Pays')
                    ->state(fn (Subscription $record): string => $record->describePrice())
                    ->alignEnd()
                    // Snapshotted at signup, so a repriced plan does not rewrite
                    // what an existing member is told they pay.
                    ->tooltip('What they were signed up at, not the plan\'s current price.'),

                TextColumn::make('allowances')
                    ->label('Grants')
                    ->state(fn (Subscription $record): string => $record->describeAllowances())
                    ->wrap()
                    ->badge(fn (Subscription $record): bool => $record->termsDifferFromPlan())
                    ->color(fn (Subscription $record): string => $record->termsDifferFromPlan() ? 'warning' : 'gray')
                    ->tooltip(fn (Subscription $record): ?string => $record->termsDifferFromPlan()
                        ? 'These are the terms they signed up under, which differ from the plan as it stands now.'
                        : null),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date('j M Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('active')
                    ->label('Currently active')
                    ->toggle()
                    // Cashier's own definition of active, so this list can never
                    // disagree with what the billing side believes.
                    ->query(self::onlyActive(...)),
            ])
            ->emptyStateHeading('Nobody on this plan yet')
            ->emptyStateDescription('Subscribers appear here once they sign up. Their allowances are frozen at that moment.');
    }

    /**
     * Cashier's `active` scope, named so the builder's model is known.
     *
     * A filter closure is handed an untyped builder, where a model scope is not
     * visible to static analysis. Naming the method is enough to say which model
     * it queries.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private static function onlyActive(Builder $query): Builder
    {
        return $query->active();
    }
}
