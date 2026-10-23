<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\Subscription;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What one membership is.
 *
 * Read-only throughout. §3 gives Cashier the lifecycle, so anything editable
 * here would be a second opinion about a fact Stripe already holds — and the
 * two would diverge the first time a webhook arrived. The actions on this page
 * either go through Cashier or touch only our own tables.
 */
class SubscriptionOverview
{
    public static function configure(Schema $schema): Schema
    {
        // One column of cards, stacked — the admin's single-column convention.
        // Field groupings inside a card keep their own columns.
        return $schema->columns(1)->components([

            Section::make()->columns(4)->schema([
                TextEntry::make('member.name')
                    ->label('Member')
                    ->weight('bold')
                    ->placeholder('Account deleted')
                    ->helperText(fn (Subscription $record): ?string => $record->member?->email),

                TextEntry::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Subscription $record): string => $record->state()->label())
                    ->color(fn (Subscription $record): string => $record->state()->color()),

                TextEntry::make('plan.name')
                    ->label('Plan')
                    ->placeholder('No plan recorded'),

                TextEntry::make('price')
                    ->label('Pays')
                    // Snapshotted at signup, so a repriced plan does not rewrite
                    // what an existing member was told they pay.
                    ->state(fn (Subscription $record): string => $record->describePrice())
                    ->helperText('What they signed up at, not the plan\'s price today.'),
            ]),

            Section::make('Membership period')
                ->description('When this membership runs from, and when it runs out.')
                ->columns(4)
                ->schema([
                    TextEntry::make('term_starts_at')
                        ->label('Starts')
                        ->state(fn (Subscription $record): ?string => $record->termStartsAt()?->format('j M Y'))
                        ->helperText('When they bought it.'),

                    TextEntry::make('term_ends_at')
                        ->label('Ends')
                        // Snapshotted at purchase from the plan's duration, so
                        // editing the plan cannot move an existing member's end
                        // date. Null means a recurring plan with no end.
                        ->state(fn (Subscription $record): string => $record->accessEndsAt()?->format('j M Y') ?? 'Renews — no end date')
                        ->helperText(fn (Subscription $record): ?string => match (true) {
                            $record->onGracePeriod() => 'Cancelled, and paid up until then.',
                            $record->termHasExpired() => 'This term has finished.',
                            $record->daysRemaining() !== null => $record->daysRemaining().' days to go.',
                            default => null,
                        }),

                    TextEntry::make('trial_ends_at')
                        ->label('Trial ends')
                        ->dateTime('j M Y')
                        ->placeholder('No trial'),

                    TextEntry::make('tenure')
                        ->label('Member for')
                        ->state(fn (Subscription $record): string => $record->describeTenure()),
                ]),

            /*
             * The distinction the whole §5.5 snapshot exists for. Support's
             * recurring question is "why does this member see something the
             * plan page does not offer", and the answer is here rather than in
             * anybody's memory of when the plan was last edited.
             */
            Section::make('What they were sold')
                ->description('Frozen at signup. Editing the plan does not change this.')
                ->columns(2)
                ->schema([
                    TextEntry::make('allowances')
                        ->label('Allowances')
                        ->state(fn (Subscription $record): array => $record->allowanceLines())
                        ->listWithLineBreaks()
                        ->placeholder('No allowances recorded'),

                    TextEntry::make('plan_allowances')
                        ->label('The plan grants today')
                        ->state(fn (Subscription $record): array => $record->plan?->allowanceLines() ?? [])
                        ->listWithLineBreaks()
                        ->placeholder('No plan recorded')
                        ->color(fn (Subscription $record): string => $record->termsDifferFromPlan() ? 'warning' : 'gray')
                        ->helperText(fn (Subscription $record): ?string => $record->termsDifferFromPlan()
                            ? 'These differ. The member keeps the terms above.'
                            : null),
                ]),

            /*
             * Stripe's own identifiers, last and unemphasised. Nobody reads
             * these day to day, and the one time they matter is when Stripe is
             * the thing raising the question.
             */
            Section::make('Stripe')
                ->description('Cashier owns these. They are shown so a support ticket can be matched to a Stripe record.')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextEntry::make('stripe_id')->label('Subscription ID')->copyable(),
                    TextEntry::make('stripe_status')->label('Raw status')->badge()->color('gray'),
                    TextEntry::make('stripe_price')->label('Price ID')->copyable()->placeholder('—'),
                ]),
        ]);
    }
}
