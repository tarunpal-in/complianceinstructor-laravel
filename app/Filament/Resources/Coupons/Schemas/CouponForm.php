<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Schemas;

use App\Enums\CouponType;
use App\Enums\Format;
use App\Models\Coupon;
use App\Models\Industry;
use App\Models\Webinar;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * §8 improvement #2 — the caps the predecessor had none of, and the scope that
 * stops a blanket discount being the only kind on offer.
 */
class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        // One column, so each section stacks full-width down the page instead
        // of tiling two-up. Field pairs inside a section are kept.
        return $schema->columns(1)->components([

            Section::make()->columns(2)->schema([
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->maxLength(40)
                    ->unique(ignoreRecord: true)
                    ->default(fn (): string => Str::upper(Str::random(8)))
                    // Stored and compared upper-case, so a customer typing
                    // "save10" is not told their code is wrong.
                    ->helperText('Stored in capitals. Customers can type it in any case.')
                    ->dehydrateStateUsing(fn (?string $state): string => Str::upper(trim((string) $state))),

                Toggle::make('is_active')
                    ->label('Available')
                    ->default(true)
                    ->helperText('Turning this off stops the code working immediately, without deleting it.'),

                TextInput::make('description')
                    ->label('What this is for')
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Internal only — a note for whoever finds this in six months.'),
            ]),

            Section::make('Discount')
                ->columns(2)
                ->schema([
                    /*
                     * `discount_type`, `amount_off`, `minimum_spend` and
                     * `scopeable` are not columns — they are how a person
                     * thinks about a coupon, and CouponForm::mutate() turns
                     * them into columns and drops them.
                     *
                     * None of them is marked dehydrated(false), deliberately.
                     * Excluding a field keeps it out of the saved data
                     * entirely, so the mutator would receive nothing and
                     * quietly store a coupon worth zero.
                     */
                    Select::make('discount_type')
                        ->label('Type')
                        ->options(CouponType::options())
                        ->required()
                        ->live()
                        ->formatStateUsing(fn (?Coupon $record): string => ($record?->type() ?? CouponType::Percent)->value)
                        ->afterStateUpdated(function (string $state, callable $set): void {
                            $state === CouponType::Percent->value
                                ? $set('amount_off', null)
                                : $set('percent_off', null);
                        }),

                    TextInput::make('percent_off')
                        ->label('Percentage off')
                        ->numeric()
                        ->suffix('%')
                        ->minValue(1)
                        ->maxValue(100)
                        ->visible(fn (callable $get): bool => $get('discount_type') === CouponType::Percent->value)
                        ->required(fn (callable $get): bool => $get('discount_type') === CouponType::Percent->value)
                        ->dehydrateStateUsing(fn (mixed $state): ?int => blank($state) ? null : (int) $state),

                    TextInput::make('amount_off')
                        ->label('Amount off')
                        ->prefix('$')
                        ->numeric()
                        ->minValue(0.01)
                        ->visible(fn (callable $get): bool => $get('discount_type') === CouponType::Fixed->value)
                        ->required(fn (callable $get): bool => $get('discount_type') === CouponType::Fixed->value)
                        ->formatStateUsing(fn (?Coupon $record): string => Money::toOptionalDecimal($record?->amount_off_cents))
                        // Parsed as a string, never multiplied as a float
                        // (§4 rule 6).
                        ->helperText('Never takes off more than the qualifying items are worth.'),
                ]),

            Section::make('Limits')
                ->description('Every use writes a redemption row, so these are counted rather than trusted.')
                ->columns(2)
                ->schema([
                    TextInput::make('max_redemptions')
                        ->label('Total redemptions')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('Unlimited')
                        // Checked inside the order transaction under a lock, so
                        // two checkouts cannot both take the last one.
                        ->helperText('Across all customers. Leave empty for no limit.'),

                    TextInput::make('max_per_user')
                        ->label('Per customer')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('Unlimited')
                        // Worth stating plainly rather than discovering later.
                        ->helperText('Only counts signed-in customers — a guest checkout has no identity to count.'),

                    TextInput::make('minimum_spend')
                        ->label('Minimum spend')
                        ->prefix('$')
                        ->numeric()
                        ->minValue(0)
                        ->placeholder('None')
                        ->formatStateUsing(fn (?Coupon $record): string => Money::toOptionalDecimal($record?->minimum_spend_cents))
                        ->helperText('Measured against the qualifying items only, not the whole basket.'),

                    DateTimePicker::make('starts_at')
                        ->label('Valid from')
                        ->seconds(false)
                        ->placeholder('Immediately'),

                    DateTimePicker::make('ends_at')
                        ->label('Valid until')
                        ->seconds(false)
                        ->placeholder('No end date')
                        ->rule(static function (callable $get): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $start = $get('starts_at');

                                // A window that closes before it opens is a
                                // coupon that never works, and nothing else
                                // would ever say so.
                                if (filled($value) && filled($start) && $value <= $start) {
                                    $fail('The end has to come after the start.');
                                }
                            };
                        }),
                ]),

            Section::make('What it applies to')
                ->description('Leave both empty for the whole catalogue. Setting both means the code applies only where they overlap.')
                ->columns(2)
                ->schema([
                    Select::make('scopeable')
                        ->label('Limited to')
                        ->placeholder('Everything')
                        // One select over two tables: the client thinks "this
                        // discount is for HR" or "for that webinar", not "pick a
                        // scope type, then a record".
                        ->options(fn (): array => self::scopeOptions())
                        ->searchable()
                        ->formatStateUsing(fn (?Coupon $record): ?string => $record?->scopeable_id === null
                            ? null
                            : $record->scopeable_type.':'.$record->scopeable_id),

                    Select::make('scope_format')
                        ->label('Limited to format')
                        ->placeholder('Any format')
                        ->options(Format::options())
                        ->helperText('For example, a code that only discounts transcripts.'),
                ]),
        ]);
    }

    /**
     * Turn the form's display fields into columns.
     *
     * Three fields on this form are not columns: money is typed as decimals and
     * stored as cents, and the scope is picked from one list but stored as a
     * polymorphic pair. Both pages route through here so a coupon created and a
     * coupon edited cannot end up shaped differently.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutate(array $data): array
    {
        $data['amount_off_cents'] = Money::toOptionalCents($data['amount_off'] ?? null);
        $data['minimum_spend_cents'] = Money::toOptionalCents($data['minimum_spend'] ?? null);

        // Exactly one of the two is set, whichever the type says. Leaving the
        // other populated is how a percentage coupon starts charging a fixed
        // amount after someone switches the type back and forth.
        if (($data['discount_type'] ?? null) === CouponType::Fixed->value) {
            $data['percent_off'] = null;
        } else {
            $data['amount_off_cents'] = null;
        }

        [$data['scopeable_type'], $data['scopeable_id']] = self::splitScope($data['scopeable'] ?? null);

        unset($data['amount_off'], $data['minimum_spend'], $data['scopeable'], $data['discount_type']);

        return $data;
    }

    /**
     * "App\Models\Industry:4" back into a type and an id.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private static function splitScope(mixed $value): array
    {
        if (blank($value) || ! is_string($value) || ! str_contains($value, ':')) {
            return [null, null];
        }

        [$type, $id] = explode(':', $value, 2);

        // Only the two classes this form offers. A crafted value must not be
        // able to point the scope at an arbitrary model.
        return in_array($type, [Industry::class, Webinar::class], true)
            ? [$type, (int) $id]
            : [null, null];
    }

    /**
     * Industries and webinars in one list, keyed "Class:id".
     *
     * @return array<string, array<string, string>>
     */
    private static function scopeOptions(): array
    {
        return [
            'Industries' => Industry::query()
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Industry $industry): array => [
                    Industry::class.':'.$industry->getKey() => $industry->name,
                ])
                ->all(),

            'Webinars' => Webinar::query()
                ->orderBy('title')
                ->limit(100)
                ->get()
                ->mapWithKeys(fn (Webinar $webinar): array => [
                    Webinar::class.':'.$webinar->getKey() => $webinar->title,
                ])
                ->all(),
        ];
    }
}
