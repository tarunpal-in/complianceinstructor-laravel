<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipPlans\Schemas;

use App\Enums\AllowanceWindow;
use App\Enums\BillingPeriod;
use App\Enums\Format;
use App\Models\MembershipPlan;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * The plan builder — CLAUDE.md §6, "Plan builder, entitlement rows".
 *
 * The repeater is the whole point of §5.5. The predecessor expressed a tier as
 * 17 boolean and counter columns, so "4 live plus unlimited transcripts" needed
 * a migration and a new checkbox on a form. Here it is three rows the client
 * adds themselves.
 */
class MembershipPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                        if ($operation === 'create') {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    // No unique rule: a clash is resolved to "{slug}-2" on save
                    // and reported, rather than refusing the save.
                    ->helperText('Used in the pricing page URL. Changing it on a live plan breaks existing links.'),

                Textarea::make('description')
                    ->label('What this tier is')
                    ->rows(2)
                    ->columnSpanFull()
                    ->maxLength(1000)
                    ->helperText('Shown on the pricing card, under the plan name.'),
            ]),

            Section::make('Price and billing')
                ->columns(2)
                ->schema([
                    /*
                     * `price` is not a column — money is typed as decimals and
                     * stored as cents (§4 rule 6), and mutate() below does the
                     * conversion. Not marked dehydrated(false): excluding a
                     * field keeps it out of the saved data entirely, and the
                     * mutator would then quietly store a plan worth nothing.
                     */
                    TextInput::make('price')
                        ->label('Price')
                        ->prefix('$')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->formatStateUsing(fn (?MembershipPlan $record): string => Money::toOptionalDecimal($record?->price_cents)),

                    Select::make('billing_period')
                        ->label('Billing')
                        ->options(BillingPeriod::options())
                        ->default(BillingPeriod::Year->value)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (string $state, callable $set): void {
                            // The two fields would otherwise contradict each
                            // other: a plan that renews yearly *and* claims a
                            // twelve-month duration tells the customer two
                            // different things.
                            if ($state !== BillingPeriod::OneTime->value) {
                                $set('duration_months', null);
                            }
                        }),

                    TextInput::make('duration_months')
                        ->label('Access lasts')
                        ->suffix('months')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(120)
                        ->visible(fn (callable $get): bool => $get('billing_period') === BillingPeriod::OneTime->value)
                        ->required(fn (callable $get): bool => $get('billing_period') === BillingPeriod::OneTime->value)
                        ->helperText('A one-off with no duration would grant access forever.'),

                    TextInput::make('stripe_price_id')
                        ->label('Stripe price ID')
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->placeholder('price_...')
                        ->visible(fn (callable $get): bool => $get('billing_period') !== BillingPeriod::OneTime->value)
                        // §3 gives Cashier all recurring billing, so without one
                        // of these nobody can actually be charged.
                        ->helperText('From the Stripe dashboard. A recurring plan cannot be sold without it.'),
                ]),

            Section::make('What it grants')
                ->description('One row per format. This is what a new subscriber gets — existing members keep the terms they signed up under.')
                ->schema([
                    Repeater::make('entitlements')
                        ->hiddenLabel()
                        ->relationship()
                        ->columns(3)
                        ->defaultItems(1)
                        ->addActionLabel('Add an allowance')
                        ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                        ->schema([
                            Select::make('format')
                                ->options(Format::options())
                                ->required()
                                // UNIQUE(membership_plan_id, format) in the
                                // database; two `live` rows would silently
                                // double the allowance, so the form refuses
                                // rather than letting the insert fail.
                                ->distinct()
                                ->fixIndistinctState()
                                ->live(),

                            Toggle::make('unlimited')
                                ->label('Unlimited')
                                ->live()
                                ->afterStateUpdated(function (bool $state, callable $set): void {
                                    if ($state) {
                                        $set('quantity', null);
                                    }
                                })
                                ->helperText('No cap on this format.'),

                            TextInput::make('quantity')
                                ->label('How many')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(999)
                                ->required(fn (callable $get): bool => $get('unlimited') !== true)
                                ->visible(fn (callable $get): bool => $get('unlimited') !== true),

                            Select::make('window')
                                ->label('Resets')
                                ->options(AllowanceWindow::options())
                                ->default(AllowanceWindow::Term->value)
                                ->required()
                                ->visible(fn (callable $get): bool => $get('unlimited') !== true)
                                // The difference is easy to skim past and
                                // expensive to get wrong: four a month on a
                                // yearly plan is 48 a year, four a term is four.
                                ->helperText('Per term means once per billing period.'),
                        ])
                        /*
                         * `unlimited` is how a person thinks about it; NULL is
                         * how it is stored (§5.5 — never 0, never -1, because
                         * both are numbers arithmetic will happily use). These
                         * three callbacks are the whole translation, and they
                         * also strip the field: Model::shouldBeStrict() throws
                         * on an attribute that is not a column.
                         */
                        ->mutateRelationshipDataBeforeFillUsing(self::toFormRow(...))
                        ->mutateRelationshipDataBeforeCreateUsing(self::toRow(...))
                        ->mutateRelationshipDataBeforeSaveUsing(self::toRow(...)),
                ]),

            Section::make('Pricing card')
                ->description('The tick-and-cross list under the price. This is copy, not access — what the plan actually grants is the section above.')
                ->schema([
                    Repeater::make('highlights')
                        ->hiddenLabel()
                        ->columns(4)
                        // No blank row to start: a card with nothing to say
                        // should render nothing, not fail validation on an
                        // empty bullet nobody asked for.
                        ->defaultItems(0)
                        ->addActionLabel('Add a line')
                        ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) ? $state['label'] : null)
                        ->schema([
                            TextInput::make('label')
                                ->hiddenLabel()
                                ->required()
                                ->maxLength(120)
                                ->columnSpan(3)
                                ->placeholder('Certificate of Completion'),

                            Toggle::make('included')
                                ->label('Included')
                                ->default(true)
                                // Off shows the line crossed out, which is how a
                                // card says what the next tier up adds.
                                ->helperText('Off: shown greyed out.'),
                        ]),
                ]),

            Section::make('Availability')
                ->columns(3)
                ->schema([
                    Toggle::make('is_active')
                        ->label('On the pricing page')
                        ->default(true)
                        ->rule(static function (callable $get): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if ($value !== true) {
                                    return;
                                }

                                foreach (self::reasonsItCannotSell($get) as $reason) {
                                    $fail($reason);
                                }
                            };
                        })
                        ->helperText('A plan can only go live once it has a price, an allowance and — if recurring — a Stripe price.'),

                    Toggle::make('is_featured')
                        ->label('Highlight this tier')
                        ->helperText('The one the pricing page calls out. Usually the middle option.'),

                    TextInput::make('sort_order')
                        ->label('Position')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->helperText('Lowest first. Plans can also be dragged into order on the list.'),
                ]),
        ]);
    }

    /**
     * Why this plan cannot go live, read from the form rather than the record.
     *
     * MembershipPlan::problems() answers the same question about a saved plan.
     * This one has to work before anything is written — the entitlements are
     * still repeater state at that point, and refusing to save is far better
     * than letting a plan reach the pricing page and fail at payment.
     *
     * @return array<int, string>
     */
    private static function reasonsItCannotSell(callable $get): array
    {
        $reasons = [];

        $entitlements = $get('entitlements');

        if (! is_array($entitlements) || $entitlements === []) {
            $reasons[] = 'Add at least one allowance before putting this plan on the pricing page — otherwise a subscriber pays for no access.';
        }

        if (Money::toOptionalCents($get('price')) === null) {
            $reasons[] = 'Give this plan a price before putting it on the pricing page.';
        }

        $period = $get('billing_period');

        if ($period !== BillingPeriod::OneTime->value && blank($get('stripe_price_id'))) {
            $reasons[] = 'A recurring plan needs a Stripe price ID before anyone can subscribe to it.';
        }

        return $reasons;
    }

    /**
     * "4 live per term" as the collapsed repeater row's title.
     *
     * @param  array<string, mixed>  $state
     */
    private static function itemLabel(array $state): ?string
    {
        $format = $state['format'] ?? null;

        if (! is_string($format) || Format::tryFrom($format) === null) {
            return null;
        }

        $label = Format::from($format)->label();

        if (($state['unlimited'] ?? false) === true || ($state['quantity'] ?? null) === null) {
            return 'Unlimited '.strtolower($label);
        }

        $window = AllowanceWindow::tryFrom((string) ($state['window'] ?? '')) ?? AllowanceWindow::Term;

        return $state['quantity'].' '.strtolower($label).' '.$window->shortLabel();
    }

    /**
     * A stored row as the form sees it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function toFormRow(array $data): array
    {
        $data['unlimited'] = ($data['quantity'] ?? null) === null;

        return $data;
    }

    /**
     * The form's row as a column row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function toRow(array $data): array
    {
        if (($data['unlimited'] ?? false) === true) {
            $data['quantity'] = null;

            // An unlimited allowance has nothing to reset, but the column is
            // not nullable — term is the honest default and reads correctly
            // everywhere it is shown.
            $data['window'] ??= AllowanceWindow::Term->value;
        }

        unset($data['unlimited']);

        return $data;
    }

    /**
     * Turn the form's display fields into columns.
     *
     * Both pages route through here so a plan created and a plan edited cannot
     * end up shaped differently.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutate(array $data): array
    {
        $data['price_cents'] = Money::toCents($data['price'] ?? 0);

        // Only a one-off has a duration; only a recurring plan has a Stripe
        // price. The hidden one is cleared rather than left behind, because a
        // stale value is what makes a plan contradict itself after someone
        // switches the billing period back and forth.
        if (($data['billing_period'] ?? null) === BillingPeriod::OneTime->value) {
            $data['stripe_price_id'] = null;
        } else {
            $data['duration_months'] = null;
        }

        // A unique index does not allow two empty strings to mean "no Stripe
        // price" — only NULL does.
        if (blank($data['stripe_price_id'] ?? null)) {
            $data['stripe_price_id'] = null;
        }

        unset($data['price']);

        return $data;
    }
}
