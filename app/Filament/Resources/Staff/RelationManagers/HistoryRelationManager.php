<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * What has been done to this account.
 *
 * §8 requires every mutation written to the activity log with the actor, and
 * on this screen that is the whole point: "who gave Sarah access to refunds"
 * is a question with real consequences, and the row alone only knows the
 * answer it arrived at.
 *
 * Read-only in the strongest sense — an audit trail somebody can edit is not
 * an audit trail.
 */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'History';

    protected static ?string $modelLabel = 'change';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('causer'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('j M Y, H:i')
                    ->description(fn (Activity $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable()
                    ->width('12rem'),

                TextColumn::make('causer.name')
                    ->label('Who')
                    // No causer means nobody was signed in — a seeder, a
                    // console command, the invitation flow itself.
                    ->placeholder('The system')
                    ->width('12rem'),

                TextColumn::make('description')
                    ->label('What happened')
                    ->badge()
                    ->color('gray'),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing recorded')
            ->emptyStateDescription('Changes appear here as this account is given access, changed or switched off.');
    }
}
