<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Every decision made about this comment.
 *
 * The row carries the *current* state — published, or spam, or waiting. It
 * cannot say that something was published, taken down after a complaint, and
 * put back, which is precisely the sequence somebody asks about afterwards.
 *
 * From `activity_log`, which §9 already makes the audit trail for every
 * mutation: one record surfaced rather than a second history table that would
 * eventually disagree with it. Read-only, because an audit trail somebody can
 * edit is not an audit trail.
 */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'History';

    protected static ?string $modelLabel = 'decision';

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
                    // No causer means nobody was signed in: the reader posting
                    // it, or a seeder. Saying so beats a blank cell.
                    ->placeholder('The commenter')
                    ->width('12rem'),

                TextColumn::make('properties')
                    ->label('What changed')
                    ->state(fn (Activity $record): string => self::describeChange($record))
                    ->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing recorded')
            ->emptyStateDescription('Decisions appear here as this comment is published, rejected or marked as spam.');
    }

    /**
     * The change in a sentence.
     *
     * The raw property bag is a JSON diff, which is exactly what a moderator
     * should not have to read to find out who published something.
     */
    private static function describeChange(Activity $record): string
    {
        $status = data_get($record->properties, 'attributes.status');

        if (is_string($status)) {
            return 'Set to '.($status === 'approved' ? 'published' : $status).'.';
        }

        $changed = array_keys((array) data_get($record->properties, 'attributes', []));

        return $changed === [] ? 'Left by the reader.' : 'Changed: '.implode(', ', $changed).'.';
    }
}
