<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog\Tables;

use App\Models\User;
use App\Support\ActivityNarrator;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * The log, read as a sequence of events rather than a table of rows.
 *
 * Newest first and no default search: somebody arriving here has either just
 * been asked a question or is looking at what happened this morning, and both
 * start at the top.
 */
class ActivityTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('j M Y, H:i')
                    ->description(fn (Activity $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable()
                    ->width('12rem'),

                TextColumn::make('causer')
                    ->label('Who')
                    ->state(fn (Activity $record): string => ActivityNarrator::causerName($record))
                    // Nobody signed in is a real answer, not a gap — a
                    // scheduled sweep, a console command, or a visitor using a
                    // public form.
                    ->color(fn (Activity $record): string => $record->causer === null ? 'gray' : 'primary')
                    ->searchable(query: self::searchCauser(...))
                    ->width('12rem'),

                TextColumn::make('description')
                    ->label('What happened')
                    ->state(fn (Activity $record): string => ActivityNarrator::describe($record))
                    ->wrap(),

                TextColumn::make('subject')
                    ->label('Record')
                    ->state(fn (Activity $record): string => ActivityNarrator::recordLabel($record))
                    ->url(fn (Activity $record): ?string => ActivityNarrator::recordUrl($record))
                    ->openUrlInNewTab()
                    ->color(fn (Activity $record): string => ActivityNarrator::recordUrl($record) === null ? 'gray' : 'primary')
                    ->wrap()
                    ->width('16rem'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Kind')
                    ->options(fn (): array => collect(ActivityNarrator::KINDS)
                        ->map(fn (array $kind): string => $kind['label'])
                        ->all())
                    ->multiple(),

                SelectFilter::make('event')
                    ->label('Event')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Changed',
                        'deleted' => 'Deleted',
                        'restored' => 'Restored',
                    ])
                    ->multiple(),

                SelectFilter::make('causer_id')
                    ->label('Who')
                    ->options(fn (): array => User::query()
                        ->whereHas('roles')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),

                /*
                 * A date range rather than a preset list. The question that
                 * brings somebody here usually comes with a date attached —
                 * "the refund on the 14th" — and a preset of "last 7 days"
                 * cannot answer it.
                 */
                Filter::make('period')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(self::withinPeriod(...))
                    ->indicateUsing(self::describePeriod(...)),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([ViewAction::make()])
            // Deliberately nothing else. No edit, no delete, no bulk action.
            ->emptyStateHeading('Nothing recorded yet')
            ->emptyStateDescription('Entries appear here as records are created, changed and deleted across the admin.');
    }

    /**
     * Search by the name of whoever did it.
     *
     * The column holds an id, so without this the search box would silently
     * ignore the one thing people type into it.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private static function searchCauser(Builder $query, string $search): Builder
    {
        return $query->whereHasMorph('causer', [User::class], fn (Builder $causer): Builder => $causer
            ->where('name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%"));
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<Activity>
     */
    private static function withinPeriod(Builder $query, array $data): Builder
    {
        return $query
            ->when(
                filled($data['from'] ?? null),
                fn (Builder $from): Builder => $from->whereDate('created_at', '>=', $data['from']),
            )
            ->when(
                filled($data['until'] ?? null),
                fn (Builder $until): Builder => $until->whereDate('created_at', '<=', $data['until']),
            );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private static function describePeriod(array $data): array
    {
        $indicators = [];

        if (filled($data['from'] ?? null)) {
            $indicators[] = 'From '.Carbon::parse((string) $data['from'])->format('j M Y');
        }

        if (filled($data['until'] ?? null)) {
            $indicators[] = 'Until '.Carbon::parse((string) $data['until'])->format('j M Y');
        }

        return $indicators;
    }
}
