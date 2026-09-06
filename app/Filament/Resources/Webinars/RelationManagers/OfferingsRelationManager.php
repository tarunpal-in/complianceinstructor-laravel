<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\RelationManagers;

use App\Enums\Format;
use App\Models\Offering;
use App\Models\Webinar;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Purchase options — the rows behind the detail page's purchase panel.
 *
 * §5.3 makes `offerings` "the ONLY place a price is ever read from", so this
 * screen is where the money for a webinar is decided. Nothing here is ever
 * taken from a request at checkout: totals are recomputed from these rows
 * inside the order transaction (§14 #1).
 *
 * An offering grants one or more formats, which is what makes the comp's four
 * bundles possible. §5.2 keys entitlements per format, so a three-format bundle
 * fans out into three grants.
 */
class OfferingsRelationManager extends RelationManager
{
    protected static string $relationship = 'offerings';

    protected static ?string $title = 'Purchase options';

    protected static ?string $modelLabel = 'purchase option';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([

                CheckboxList::make('format_values')
                    ->label('Formats granted')
                    ->required()
                    ->columnSpanFull()
                    ->columns(2)
                    // Restricted to what this webinar's type may sell, so a
                    // recorded webinar cannot be given a live option at all —
                    // the publish gate would refuse it later anyway.
                    ->options(fn (): array => collect($this->ownerWebinar()->type->allowedFormats())
                        ->mapWithKeys(static fn (Format $format): array => [$format->value => $format->label()])
                        ->all())
                    ->helperText('Tick several to sell a bundle. Each one becomes a separate entitlement when bought.')
                    // Filtering the options hides the live format on a recorded
                    // webinar, but a crafted request would still submit it, and
                    // §14 is emphatic that the request is never trusted. The
                    // rule is the actual guard; the options list is a courtesy.
                    ->rule(function (): Closure {
                        return function (string $attribute, mixed $value, Closure $fail): void {
                            $allowed = array_map(
                                static fn (Format $format): string => $format->value,
                                $this->ownerWebinar()->type->allowedFormats(),
                            );

                            foreach ((array) $value as $format) {
                                if (! in_array($format, $allowed, true)) {
                                    $fail("A {$this->ownerWebinar()->type->label()} webinar cannot sell that format.");
                                }
                            }
                        };
                    })
                    // Not a column: the formats live on the offering_format
                    // pivot, written after the record exists.
                    ->dehydrated(false)
                    ->formatStateUsing(fn (?Offering $record): array => $record
                        ? $record->formatEnums()->map(static fn (Format $format): string => $format->value)->all()
                        : [])
                    ->saveRelationshipsUsing(function (Offering $record, mixed $state): void {
                        $record->formats()->delete();

                        foreach ((array) $state as $value) {
                            $record->formats()->create(['format' => $value]);
                        }
                    }),

                TextInput::make('price_cents')
                    ->label('Price')
                    ->prefix('$')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->formatStateUsing(fn (?int $state): string => self::toDecimal($state))
                    ->dehydrateStateUsing(fn (mixed $state): int => self::toCents($state) ?? 0),

                TextInput::make('addon_price_cents')
                    ->label('Add-on price')
                    ->prefix('$')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Charged when this webinar is already in the cart. Leave empty if it is not sold as an add-on.')
                    ->formatStateUsing(fn (?int $state): string => self::toDecimal($state))
                    ->dehydrateStateUsing(fn (mixed $state): ?int => self::toCents($state)),

                TextInput::make('compare_at_cents')
                    ->label('Compare-at price')
                    ->prefix('$')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Shown struck through. Leave empty for no discount.')
                    ->formatStateUsing(fn (?int $state): string => self::toDecimal($state))
                    ->dehydrateStateUsing(fn (mixed $state): ?int => self::toCents($state)),

                TextInput::make('seats_per_unit')
                    ->label('Seats per unit')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->helperText('How many attendees one purchase covers.'),

                TextInput::make('sku')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->default(fn (): string => Str::upper(Str::random(10)))
                    ->helperText('Must be unique across the whole catalogue.'),

                TextInput::make('sort_order')
                    ->label('Position')
                    ->numeric()
                    ->default(0)
                    ->helperText('Order in the purchase panel; lower first.'),

                Toggle::make('is_active')
                    ->label('On sale')
                    ->default(true)
                    ->helperText('Turning every option off makes the webinar unbuyable.'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('label')
                    ->label('Option')
                    // Composed from the formats rather than stored, so the name
                    // can never drift from what the option actually grants.
                    ->state(fn (Offering $record): string => $record->label())
                    ->description(fn (Offering $record): string => $record->sku)
                    ->wrap(),

                TextColumn::make('price_cents')
                    ->label('Price')
                    ->money('USD', divideBy: 100)
                    ->sortable(),

                TextColumn::make('addon_price_cents')
                    ->label('Add-on')
                    ->money('USD', divideBy: 100)
                    ->placeholder('Not an add-on')
                    ->toggleable(),

                TextColumn::make('seats_per_unit')
                    ->label('Seats')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('On sale')
                    ->boolean(),

                TextColumn::make('sort_order')
                    ->label('Position')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('Add option')
                    ->visible(fn (): bool => auth()->user()?->can('update_webinar') ?? false),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('No purchase options yet')
            ->emptyStateDescription('A webinar with no option cannot be bought, and cannot be published.');
    }

    private function ownerWebinar(): Webinar
    {
        /** @var Webinar $webinar */
        $webinar = $this->getOwnerRecord();

        return $webinar;
    }

    /**
     * Cents to a decimal string for display — "27900" becomes "279.00".
     *
     * Integer arithmetic only. §4 rule 6 bans float arithmetic on a price, and
     * dividing by 100 to render is exactly where a cent goes missing.
     */
    private static function toDecimal(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A typed decimal back to cents — "279.5" becomes 27950.
     *
     * Parsed as a string rather than multiplied as a float: (float) '19.99' *
     * 100 is 1998.9999999999998, and casting that to int truncates to 1998.
     */
    private static function toCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', trim((string) $value), 2), 2, '0');

        $whole = (int) preg_replace('/\D/', '', $whole);
        $fraction = (int) str_pad(substr(preg_replace('/\D/', '', $fraction) ?? '0', 0, 2), 2, '0');

        return $whole * 100 + $fraction;
    }
}
