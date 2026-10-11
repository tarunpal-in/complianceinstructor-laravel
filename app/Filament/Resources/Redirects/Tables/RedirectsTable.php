<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Tables;

use App\Models\Redirect;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')
                    ->label('From')
                    ->searchable()
                    ->copyable()
                    ->wrap(),

                TextColumn::make('to_path')
                    ->label('To')
                    ->searchable()
                    ->url(fn (Redirect $record): string => str_starts_with($record->to_path, '/')
                        ? url($record->to_path)
                        : $record->to_path)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->wrap(),

                TextColumn::make('status_code')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state === 302 ? '302 temporary' : '301 permanent')
                    ->color(fn (int $state): string => $state === 302 ? 'warning' : 'success'),

                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Redirect::SOURCE_SLUG_CHANGE => 'Slug change',
                        Redirect::SOURCE_IMPORT => 'Imported',
                        default => 'Added by hand',
                    })
                    ->color('gray'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('hits')
                    ->label('Hits')
                    ->numeric()
                    ->sortable()
                    ->description(fn (Redirect $record): ?string => $record->last_hit_at?->diffForHumans()),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('source')
                    ->options([
                        Redirect::SOURCE_MANUAL => 'Added by hand',
                        Redirect::SOURCE_SLUG_CHANGE => 'Slug change',
                        Redirect::SOURCE_IMPORT => 'Imported',
                    ]),

                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No redirects yet')
            ->emptyStateDescription('Redirects appear here when you add one, when a slug changes, or when you import the old site\'s URLs.');
    }
}
