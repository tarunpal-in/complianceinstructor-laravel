<?php

declare(strict_types=1);

namespace App\Filament\Resources\Entitlements\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Everything that has happened to one grant.
 *
 * The row on `entitlements` is the *current* state: GrantEntitlement restores a
 * revoked row rather than writing a second, which is what keeps a quota honest
 * against replayed webhooks (§14 #5) but means the row alone cannot say that
 * access was given, taken back, and given again.
 *
 * These entries can. They come from `activity_log`, which §9 already makes the
 * audit trail for every mutation — so this is one record surfaced rather than a
 * second history table that would eventually disagree with it.
 *
 * Read-only in the strongest sense: an audit trail somebody can edit is not an
 * audit trail.
 */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'History';

    protected static ?string $modelLabel = 'event';

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

                TextColumn::make('description')
                    ->label('What happened')
                    ->badge()
                    ->color(fn (Activity $record): string => match ($record->description) {
                        'Access granted', 'Access restored' => 'success',
                        'Given back' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('causer.name')
                    ->label('Who')
                    // No causer means nobody was signed in — a webhook, a
                    // scheduled job, a seeder. Saying so beats a blank cell.
                    ->placeholder('The system')
                    ->width('12rem'),

                TextColumn::make('properties')
                    ->label('Detail')
                    ->state(fn (Activity $record): string => self::describeChange($record))
                    ->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing recorded')
            ->emptyStateDescription('Events appear here as this grant is given, taken back or restored.');
    }

    /**
     * The changed attributes in a sentence.
     *
     * The raw property bag is a JSON diff, which is exactly the thing a support
     * person should not have to read to answer a customer.
     */
    private static function describeChange(Activity $record): string
    {
        $note = data_get($record->properties, 'attributes.note');

        if (is_string($note) && trim($note) !== '') {
            return $note;
        }

        $changed = array_keys((array) data_get($record->properties, 'attributes', []));

        return $changed === [] ? '—' : 'Changed: '.implode(', ', $changed);
    }
}
