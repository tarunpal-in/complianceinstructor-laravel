<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorePages\Tables;

use App\Enums\CorePageKey;
use App\Models\CorePage;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CorePagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Page')
                    ->weight('bold')
                    ->formatStateUsing(fn (CorePageKey $state): string => $state->label())
                    // The address underneath, so the client knows which page this edits.
                    ->description(fn (CorePage $record): string => $record->key->path()),

                TextColumn::make('hero_title')
                    ->label('Hero title')
                    ->wrap()
                    ->limit(70),

                TextColumn::make('updated_at')
                    ->label('Edited')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('id')
            // Four fixed rows: no pagination, no create, no bulk actions.
            ->paginated(false)
            ->recordActions([
                self::visitAction(),
                EditAction::make(),
            ]);
    }

    /** Open the public page itself. */
    private static function visitAction(): Action
    {
        return Action::make('visit')
            ->label('View')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (CorePage $record): string => $record->key->path())
            ->openUrlInNewTab();
    }
}
