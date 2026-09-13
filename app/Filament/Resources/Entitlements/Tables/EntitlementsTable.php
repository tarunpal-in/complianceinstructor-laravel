<?php

declare(strict_types=1);

namespace App\Filament\Resources\Entitlements\Tables;

use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Models\Entitlement;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EntitlementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Widths and wrapping set deliberately: a table cell is
                // whitespace-nowrap by default, and a member's email beside a
                // webinar title would otherwise push the table sideways.
                TextColumn::make('user.name')
                    ->label('Member')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Entitlement $record): string => $record->user->email ?? 'Account deleted')
                    ->wrap()
                    ->width('16rem'),

                TextColumn::make('webinar.title')
                    ->label('Webinar')
                    ->searchable()
                    ->wrap()
                    ->width('20rem'),

                TextColumn::make('format')
                    ->label('Format')
                    ->badge()
                    ->formatStateUsing(fn (Entitlement $record): string => $record->format->label()),

                TextColumn::make('source_type')
                    ->label('How')
                    ->badge()
                    ->formatStateUsing(fn (Entitlement $record): string => $record->source_type->label())
                    ->color(fn (Entitlement $record): string => $record->source_type->color()),

                TextColumn::make('granted_at')
                    ->label('Granted')
                    ->dateTime('j M Y')
                    ->sortable(),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Entitlement $record): string => $record->describeState())
                    ->color(fn (Entitlement $record): string => $record->stateColor())
                    ->description(fn (Entitlement $record): ?string => match (true) {
                        $record->isRevoked() => 'by '.($record->revokedBy->name ?? 'staff'),
                        $record->expires_at !== null => 'until '.$record->expires_at->format('j M Y'),
                        default => null,
                    }),
            ])
            ->defaultSort('granted_at', 'desc')
            ->filters([
                SelectFilter::make('source_type')
                    ->label('How it was granted')
                    ->options(EntitlementSource::options()),

                SelectFilter::make('format')
                    ->label('Format')
                    ->options(Format::options()),

                /*
                 * Deliberately no filters for active, given back or comped. The
                 * tabs already answer those, and a default filter doing the
                 * same job silently narrowed every tab on top of its own query
                 * — the "Given back" tab showed nothing, because the filter was
                 * quietly excluding exactly what the tab existed to show.
                 *
                 * This one is the question the tabs cannot express: access that
                 * runs out soon, whoever it belongs to and however it was got.
                 */
                Filter::make('expiring')
                    ->label('Expiring within a week')
                    ->toggle()
                    ->query(self::onlyExpiringSoon(...)),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([ViewAction::make()])
            // Nothing in bulk: every row is one named person's access, and none
            // of it should be changed forty at a time by accident.
            ->toolbarActions([])
            ->emptyStateHeading('No access granted yet')
            ->emptyStateDescription('Grants appear here as customers buy, spend a membership allowance, or are given access by staff.');
    }

    /**
     * Named rather than inline: a filter is handed an untyped builder, where a
     * model scope is invisible to static analysis.
     *
     * @param  Builder<Entitlement>  $query
     * @return Builder<Entitlement>
     */
    private static function onlyExpiringSoon(Builder $query): Builder
    {
        return $query
            ->active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addWeek());
    }
}
