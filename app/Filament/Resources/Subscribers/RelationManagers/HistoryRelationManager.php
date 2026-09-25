<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\RelationManagers;

use App\Enums\SubscriberStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Everything that has happened to this address.
 *
 * The most useful panel on the most-questioned table here. The row knows its
 * current state; it cannot say that somebody subscribed, unsubscribed, and was
 * put back on by a member of staff — which is precisely the sequence behind "I
 * unsubscribed from you months ago".
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
                    // No causer means nobody was signed in: the person
                    // themselves, through the footer box or an unsubscribe
                    // link. On this table that is the common case and it is
                    // worth naming rather than leaving blank.
                    ->placeholder('They did it themselves')
                    ->width('12rem'),

                TextColumn::make('properties')
                    ->label('What happened')
                    ->state(fn (Activity $record): string => self::describeChange($record))
                    ->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing recorded')
            ->emptyStateDescription('Changes appear here as this address joins the list, leaves it, or is put back on.');
    }

    /**
     * The change in a sentence.
     *
     * Creation is handled first and separately: on a create every logged
     * attribute sits in the property bag, so describing them one by one turns a
     * brand-new row into a list of changes that never happened. The inquiries
     * screen shipped with exactly that bug.
     */
    private static function describeChange(Activity $record): string
    {
        if ($record->event === 'created') {
            return 'Joined the list.';
        }

        if ($record->event === 'deleted') {
            return 'Erased.';
        }

        /** @var array<string, mixed> $changed */
        $changed = (array) data_get($record->properties, 'attributes', []);

        $status = $changed['status'] ?? null;

        // The status is the only change on this table anybody reads the history
        // for. The timestamps that move with it would just repeat it.
        if (is_string($status)) {
            return 'Set to '.(SubscriberStatus::tryFrom($status)?->label() ?? $status).'.';
        }

        $keys = array_keys($changed);

        return $keys === [] ? 'No visible change.' : 'Changed: '.implode(', ', $keys).'.';
    }
}
