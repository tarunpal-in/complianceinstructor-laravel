<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Schemas;

use App\Models\Redirect;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Creating or editing a redirect, with the brief's avoid-list enforced here.
 *
 * The validation is the point of the screen. A redirect that chains, loops, or
 * points away from a live page is worse than no redirect, so each is a
 * server-side rule rather than a hint — the save is refused with a sentence that
 * says what to do instead.
 */
class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('from_path')
                        ->label('Old path')
                        ->required()
                        ->maxLength(255)
                        ->helperText('The URL that moved, without the domain — e.g. /old-webinar-name. A trailing slash and capitalisation do not matter.')
                        // Normalised so /Old/ and /old are the same key, and the
                        // uniqueness check below compares like with like.
                        ->dehydrateStateUsing(fn (string $state): string => Redirect::normalise($state))
                        ->rule(static fn (?Redirect $record): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($record): void {
                            $from = Redirect::normalise((string) $value);

                            $clash = Redirect::query()
                                ->where('from_path', $from)
                                ->when($record !== null, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->exists();

                            if ($clash) {
                                $fail('There is already a redirect from that path.');
                            }

                            if (Redirect::shadowsLiveContent($from)) {
                                $fail('That path currently shows a live page. A redirect here would hide it — pick the path that actually moved.');
                            }
                        }),

                    TextInput::make('to_path')
                        ->label('Redirect to')
                        ->required()
                        ->maxLength(255)
                        ->helperText('An internal path like /webinars/new-name/ or a full https:// address for an external move.')
                        ->dehydrateStateUsing(fn (string $state): string => Redirect::normaliseTarget($state))
                        ->rule(static fn (Get $get, ?Redirect $record): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($get, $record): void {
                            $problem = Redirect::problemWith(
                                (string) $get('from_path'),
                                (string) $value,
                                $record?->getKey(),
                            );

                            if ($problem !== null) {
                                $fail($problem);
                            }
                        }),

                    Select::make('status_code')
                        ->label('Type')
                        ->options([
                            301 => '301 — permanent (the usual choice)',
                            302 => '302 — temporary',
                        ])
                        ->default(301)
                        ->selectablePlaceholder(false)
                        ->helperText('Permanent passes the old URL\'s search ranking to the new one. Use temporary only for a genuinely short-lived move.'),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Turn off to pause a redirect without losing its history.'),

                    TextInput::make('note')
                        ->label('Note')
                        ->maxLength(255)
                        ->helperText('Optional. Why this redirect exists.')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
