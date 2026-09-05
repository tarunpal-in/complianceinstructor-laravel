<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\Tables;

use App\Enums\Format;
use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Webinar;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WebinarsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('card')
                    ->collection('card')
                    ->conversion('card')
                    ->label(''),

                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Webinar $record): string => $record->slug),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (WebinarType $state): string => $state->label())
                    ->color(fn (WebinarType $state): string => $state === WebinarType::Live ? 'danger' : 'gray'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WebinarStatus $state): string => $state->label())
                    ->color(fn (WebinarStatus $state): string => $state->color()),

                TextColumn::make('speaker.name')
                    ->label('Speaker')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('industry.name')
                    ->label('Industry')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('scheduled_at')
                    ->label('Starts')
                    ->dateTime('j M Y · H:i')
                    ->sortable()
                    ->placeholder('On demand'),

                TextColumn::make('offerings_count')
                    ->label('Options')
                    ->badge()
                    // Zero is the state that breaks add-to-cart, so it is
                    // coloured as a problem rather than a neutral count.
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : 'success')
                    ->sortable(),

                TextColumn::make('accreditations_count')
                    ->label('Credits')
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'warning' : 'gray')
                    ->toggleable(),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options(WebinarType::options()),
                SelectFilter::make('status')->options(WebinarStatus::options()),
                SelectFilter::make('industry')->relationship('industry', 'name')->searchable()->preload(),
                SelectFilter::make('speaker')->relationship('speaker', 'name')->searchable()->preload(),

                SelectFilter::make('accreditation')
                    ->relationship('accreditations', 'code')
                    ->label('Accreditation'),

                /*
                 * The two filters that find records which will fail in front of
                 * a customer rather than in a report.
                 */
                Filter::make('without_offering')
                    ->label('No purchase option')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave(
                        'offerings',
                        fn (Builder $offerings): Builder => $offerings->where('is_active', true),
                    ))
                    ->toggle(),

                Filter::make('without_join_link')
                    ->label('Live, starting soon, no join link')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('type', WebinarType::Live->value)
                        ->whereNotNull('scheduled_at')
                        ->whereBetween('scheduled_at', [now(), now()->addDays(2)])
                        ->whereDoesntHave(
                            'assets',
                            fn (Builder $assets): Builder => $assets
                                ->where('format', Format::Live->value)
                                ->where('is_active', true),
                        ))
                    ->toggle(),

                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
