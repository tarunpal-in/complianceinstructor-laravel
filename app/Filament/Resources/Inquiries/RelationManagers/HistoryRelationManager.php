<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * What has happened to this inquiry.
 *
 * The row holds the current state — who owns it, what it was last set to. It
 * cannot say that it was handed from one person to another, closed, and
 * reopened, which is the sequence argued about when a deal is claimed twice.
 *
 * From `activity_log`, which §9 already makes the audit trail for every
 * mutation: one record surfaced rather than a second history table that would
 * eventually disagree with it. It is also what makes an overwritten note
 * recoverable, since every version is in the property bag.
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
                    // No causer means nobody was signed in — a form submission,
                    // an import, a seeder. Saying so beats a blank cell.
                    ->placeholder('The system')
                    ->width('12rem'),

                TextColumn::make('properties')
                    ->label('What changed')
                    ->state(fn (Activity $record): string => self::describeChange($record))
                    ->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing recorded')
            ->emptyStateDescription('Changes appear here as this inquiry is handed on, answered and closed.');
    }

    /**
     * The change in a sentence.
     *
     * The raw property bag is a JSON diff, which is exactly what somebody
     * picking up an inquiry should not have to read to find out what happened to
     * it last.
     */
    private static function describeChange(Activity $record): string
    {
        /** @var array<string, mixed> $changed */
        $changed = (array) data_get($record->properties, 'attributes', []);

        $parts = [];

        foreach ($changed as $key => $value) {
            $parts[] = match ($key) {
                'status' => 'Status set to '.(is_string($value) ? $value : '—'),
                'assigned_to' => $value === null ? 'Owner cleared' : 'Handed to somebody',
                'contacted_at' => 'Marked as answered',
                'note' => 'Notes updated',
                'marketing_opt_in' => $value ? 'Agreed to email' : 'Withdrew email consent',
                default => 'Changed '.$key,
            };
        }

        return $parts === [] ? 'Created.' : implode('. ', $parts).'.';
    }
}
