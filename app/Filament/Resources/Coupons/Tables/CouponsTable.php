<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Tables;

use App\Enums\CouponType;
use App\Enums\Format;
use App\Models\Coupon;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold')
                    ->description(fn (Coupon $record): ?string => $record->description),

                TextColumn::make('discount')
                    ->label('Discount')
                    ->badge()
                    ->state(fn (Coupon $record): string => $record->describeDiscount())
                    ->color(fn (Coupon $record): string => $record->type()->color()),

                TextColumn::make('scope')
                    ->label('Applies to')
                    ->state(fn (Coupon $record): string => $record->describeScope())
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('redemptions_count')
                    ->label('Used')
                    // No ->counts(): the resource query already adds it.
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state, Coupon $record): string => $record->max_redemptions === null
                        ? (string) $state
                        : $state.' of '.$record->max_redemptions)
                    ->color(fn (Coupon $record): string => $record->isExhausted() ? 'danger' : 'gray'),

                TextColumn::make('minimum_spend_cents')
                    ->label('Min. spend')
                    ->state(fn (Coupon $record): string => $record->minimum_spend_cents === null
                        ? '—'
                        : Money::format($record->minimum_spend_cents, $record->currency))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('usable')
                    ->label('Live')
                    ->boolean()
                    ->state(fn (Coupon $record): bool => $record->isUsable())
                    // Four separate reasons a code can be dead, and the client
                    // will otherwise ask why a coupon "isn't working".
                    ->tooltip(fn (Coupon $record): ?string => $record->unusableReason()),

                TextColumn::make('ends_at')
                    ->label('Until')
                    ->dateTime('j M Y')
                    ->placeholder('No end date')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                /*
                 * The one the client actually wants: what a customer could use
                 * this minute. Active, started, unexpired — and, unlike the
                 * others, not expressible as a single column.
                 */
                Filter::make('live')
                    ->label('Usable right now')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn('id', Coupon::usableIds())),

                TernaryFilter::make('is_active')
                    ->label('Switched on')
                    ->placeholder('Any'),

                SelectFilter::make('type')
                    ->label('Discount type')
                    ->options(CouponType::options())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        CouponType::Percent->value => $query->whereNotNull('percent_off'),
                        CouponType::Fixed->value => $query->whereNotNull('amount_off_cents'),
                        default => $query,
                    }),

                SelectFilter::make('scope_format')
                    ->label('Format')
                    ->options(Format::options()),

                Filter::make('expired')
                    ->label('Expired')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '<', now())),

                Filter::make('exhausted')
                    ->label('Fully redeemed')
                    ->toggle()
                    // Compared against the counted rows rather than a column, so
                    // it cannot disagree with the cap the checkout enforces.
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('max_redemptions')
                        ->whereRaw('(select count(*) from coupon_redemptions where coupon_redemptions.coupon_id = coupons.id) >= coupons.max_redemptions')),

                /*
                 * Load-bearing, not decoration. The resource query strips the
                 * soft-delete scope so a deleted coupon can still be opened and
                 * restored — which also means nothing hides it from the list.
                 * This filter's default state is `withoutTrashed()`, so it is
                 * what puts the scope back. Without it a deleted coupon sits in
                 * the list looking live, and the delete appears to have done
                 * nothing.
                 */
                TrashedFilter::make()->label('Deleted coupons'),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    /*
                     * No ForceDeleteBulkAction. §5.1 soft-deletes anything
                     * financial: a redemption row names the coupon it was taken
                     * against, and destroying the coupon would make historic
                     * discounts unreadable.
                     */
                ]),
            ])
            ->emptyStateHeading('No coupons yet')
            ->emptyStateDescription('Create one to run a promotion. Codes are validated on the server and never listed to customers.');
    }
}
