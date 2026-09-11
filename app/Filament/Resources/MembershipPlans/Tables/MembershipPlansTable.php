<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipPlans\Tables;

use App\Enums\BillingPeriod;
use App\Models\MembershipPlan;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class MembershipPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    // Truncated rather than wrapped: a two-line description on
                    // every row sets the height of the whole table, and the
                    // full text is one click away on the record itself.
                    ->description(fn (MembershipPlan $record): ?string => $record->description)
                    ->wrap(false)
                    ->limit(60),

                TextColumn::make('price_cents')
                    ->label('Price')
                    ->state(fn (MembershipPlan $record): string => Money::format($record->price_cents, $record->currency))
                    ->description(fn (MembershipPlan $record): string => $record->billing_period->label())
                    ->alignEnd()
                    ->sortable(),

                /*
                 * The column the whole §5.5 redesign exists to make possible:
                 * the value of a tier at a glance, without opening it. The
                 * predecessor's 17 boolean columns could not be summarised at
                 * all.
                 *
                 * A list, not the joined sentence. Given one long string the
                 * cell wraps it at whatever character the column edge lands on,
                 * so "3 live webinars per term · 3 recordings per term" became
                 * a dozen ragged lines and dragged the table into a horizontal
                 * scroll. Two lines shown, the rest behind "+n more", so a
                 * four-format tier is no taller than a one-format tier.
                 */
                TextColumn::make('allowances')
                    ->label('Grants')
                    ->state(fn (MembershipPlan $record): array => $record->allowanceLines())
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('Grants nothing yet')
                    ->size('sm'),

                TextColumn::make('active_subscribers_count')
                    ->label('Members')
                    // No ->counts(): the resource query already adds it, and
                    // adding it twice puts the same alias in the select twice —
                    // which MySQL rejects outright.
                    ->alignEnd()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->tooltip('Subscriptions that are currently active or in a grace period.'),

                IconColumn::make('sellable')
                    ->label('Sellable')
                    ->boolean()
                    ->state(fn (MembershipPlan $record): bool => $record->isSellable())
                    // A plan can be broken in four different ways, and the
                    // client will otherwise just ask why nobody can subscribe.
                    ->tooltip(fn (MembershipPlan $record): ?string => implode(' ', $record->problems()) ?: null),

                IconColumn::make('is_active')
                    ->label('Live')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->toggleable(),
            ])
            // Dragged into order, because the order of tiers on a pricing page
            // is a commercial decision the client should not need a developer
            // for.
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('On the pricing page')
                    ->placeholder('Any'),

                SelectFilter::make('billing_period')
                    ->label('Billing')
                    ->options(BillingPeriod::options()),

                /*
                 * Load-bearing, not decoration. The resource query strips the
                 * soft-delete scope so a deleted plan can still be opened and
                 * restored — which also means nothing hides it from the list.
                 * This filter's default state is `withoutTrashed()`, so it is
                 * what puts the scope back. Without it a deleted plan sits in
                 * the list looking live, and the delete appears to have done
                 * nothing.
                 */
                TrashedFilter::make()->label('Deleted plans'),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    // The same guard the edit page applies, from the same place,
                    // so a plan cannot be deleted from the list in a way it
                    // could not be deleted from its own page.
                    ->before(function (DeleteAction $action, MembershipPlan $record): void {
                        $reason = $record->deletionBlockReason();

                        if ($reason === null) {
                            return;
                        }

                        Notification::make()
                            ->danger()
                            ->title('There are still members on this plan')
                            ->body($reason)
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    /*
                     * No ForceDeleteBulkAction, deliberately. §5.1 soft-deletes
                     * anything financial, and a plan is what a subscription
                     * names: destroying the row would leave paying members
                     * pointing at nothing. Deleted is as far as this goes.
                     */
                ]),
            ])
            ->emptyStateHeading('No membership plans yet')
            ->emptyStateDescription('A plan is a price and a set of allowances. Create one to start selling memberships.');
    }
}
