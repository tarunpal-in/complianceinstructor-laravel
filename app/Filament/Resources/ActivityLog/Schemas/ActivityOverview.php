<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog\Schemas;

use App\Support\ActivityNarrator;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

/**
 * One entry, with the before and after spelled out.
 *
 * The list says what changed; this says what it changed *from*. That is the
 * difference between "somebody edited the refund window" and "somebody took it
 * from 60 minutes to 0", and only the second one answers the question that
 * brought anybody here.
 */
class ActivityOverview
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()->columns(4)->schema([
                TextEntry::make('causer')
                    ->label('Who')
                    ->weight('bold')
                    ->state(fn (Activity $record): string => ActivityNarrator::causerName($record))
                    ->helperText(fn (Activity $record): ?string => $record->causer === null
                        // Not a gap: a scheduled sweep, a console command, or
                        // a visitor using a public form.
                        ? 'Nobody was signed in.'
                        : null),

                TextEntry::make('created_at')
                    ->label('When')
                    ->dateTime('j M Y, H:i:s')
                    ->helperText(fn (Activity $record): ?string => $record->created_at?->diffForHumans()),

                TextEntry::make('log_name')
                    ->label('Kind')
                    ->badge()
                    ->state(fn (Activity $record): string => ActivityNarrator::kind($record)),

                TextEntry::make('event')
                    ->label('Event')
                    ->badge()
                    ->state(fn (Activity $record): string => (string) ($record->event ?? 'updated'))
                    ->color(fn (Activity $record): string => match ($record->event) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
            ]),

            Section::make('The record')
                ->columns(2)
                ->schema([
                    TextEntry::make('subject')
                        ->label('What it happened to')
                        ->state(fn (Activity $record): string => ActivityNarrator::recordLabel($record))
                        ->url(fn (Activity $record): ?string => ActivityNarrator::recordUrl($record))
                        ->openUrlInNewTab(),

                    TextEntry::make('subject_id')
                        ->label('Reference')
                        // Kept even when the record is gone: it is what a
                        // backup or an export would be searched on.
                        ->state(fn (Activity $record): string => trim(
                            class_basename((string) $record->subject_type).' #'.($record->subject_id ?? '?')
                        ))
                        ->copyable(),
                ]),

            Section::make('What changed')
                ->description('Read straight from the entry as it was written. Nothing here can be edited.')
                ->schema([
                    RepeatableEntry::make('changes')
                        ->hiddenLabel()
                        ->state(fn (Activity $record): array => ActivityNarrator::changes($record))
                        ->columns(3)
                        ->schema([
                            TextEntry::make('attribute')->label('Field')->weight('bold'),
                            TextEntry::make('from')->label('From')->color('danger'),
                            TextEntry::make('to')->label('To')->color('success'),
                        ]),

                    TextEntry::make('no_changes')
                        ->hiddenLabel()
                        ->state('This entry records an event rather than a change of values.')
                        ->visible(fn (Activity $record): bool => ActivityNarrator::changes($record) === []),
                ]),
        ]);
    }
}
