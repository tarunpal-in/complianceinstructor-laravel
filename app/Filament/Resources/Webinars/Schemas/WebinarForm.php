<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\Schemas;

use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Setting;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * §5.3 gives a webinar nine long-text fields plus scheduling and relations. In
 * one column that is a scroll of death, so it is tabbed — Details, Content,
 * Schedule, Media.
 */
class WebinarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            // Purchase options, credits and deliverables are relation managers,
            // and a relation manager has nowhere to write until the webinar has
            // an id — so none of them can appear on this page. Saying so here is
            // cheaper than the client hunting for fields that cannot exist yet.
            Callout::make('Pricing, credits and files come next')
                ->description('Save this webinar first. Its purchase options, CEU credits and deliverables are added on the screen you land on afterwards, and it cannot be published without them.')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->color('info')
                ->columnSpanFull()
                ->visible(fn (string $operation): bool => $operation === 'create'),

            Tabs::make()->columnSpanFull()->tabs([

                Tab::make('Details')->schema([
                    Select::make('type')
                        ->label('Type')
                        ->options(WebinarType::options())
                        ->default(WebinarType::Live->value)
                        ->required()
                        // Drives whether the Schedule tab appears and which
                        // formats may be sold, so the rest of the form reacts.
                        ->live()
                        ->helperText('A recorded webinar has no session, so no date — and may never sell the live format.'),

                    Select::make('status')
                        ->options(WebinarStatus::options())
                        ->default(WebinarStatus::Draft->value)
                        ->required()
                        ->disabled(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated()
                        ->helperText('Use the Publish action rather than setting this by hand — it checks the webinar is sellable first.'),

                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                            if ($operation === 'create') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        // No unique rule: a clash is resolved to "{slug}-2" on
                        // save and reported, rather than refusing the save.
                        ->columnSpanFull()
                        // TODO(P6): write the old path into `redirects` (§5.6) on change.
                        ->helperText('Changing this on a published webinar breaks existing links to it.'),

                    Select::make('speaker_id')
                        ->label('Speaker')
                        ->relationship('speaker', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make('industry_id')
                        ->label('Industry')
                        ->relationship('industry', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('Second segment of the public URL.'),

                    Textarea::make('summary')
                        ->rows(3)
                        ->maxLength(500)
                        ->columnSpanFull()
                        ->helperText('The paragraph under the title in the hero, and on catalogue cards.'),
                ])->columns(2),

                Tab::make('Content')->schema([
                    Textarea::make('overview')->label('Webinar Overview')->rows(8),
                    Textarea::make('session_highlights')->label('Session Highlights')->rows(8),
                    Textarea::make('why_attend')->label('Why You Should Attend')->rows(8),
                    Textarea::make('who_should_attend')->label('Who Should Attend')->rows(8),
                    Textarea::make('description')->label('Description')->rows(5)
                        ->helperText('Used for search results and social previews.'),
                    Textarea::make('background')->label('Background')->rows(4),
                ])->columns(1),

                Tab::make('Schedule')
                    // A recorded webinar has no session; the fields would be
                    // meaningless. dehydratedWhenHidden keeps existing values if
                    // someone flips the type and back.
                    ->visible(fn (callable $get): bool => $get('type') === WebinarType::Live->value)
                    ->schema([
                        DateTimePicker::make('scheduled_at')
                            ->label('Starts at')
                            ->seconds(false)
                            ->required(fn (callable $get): bool => $get('type') === WebinarType::Live->value)
                            ->dehydratedWhenHidden()
                            ->live()
                            // A wrong time on a live CEU session is a refund and
                            // a compliance problem, so show what the customer
                            // will see rather than trusting the picker.
                            ->helperText(fn (callable $get): string => self::previewTime($get('scheduled_at'), $get('timezone'))),

                        Select::make('timezone')
                            ->options(self::timezones())
                            ->default('America/Los_Angeles')
                            ->required()
                            ->searchable()
                            ->live()
                            ->dehydratedWhenHidden(),

                        TextInput::make('duration_minutes')
                            ->label('Duration')
                            ->numeric()
                            ->minValue(15)
                            ->maxValue(480)
                            ->suffix('minutes')
                            ->dehydratedWhenHidden(),

                        TextInput::make('seats_total')
                            ->label('Seats')
                            ->numeric()
                            ->minValue(1)
                            ->dehydratedWhenHidden()
                            ->helperText('Leave empty for unlimited.'),
                    ])->columns(2),

                Tab::make('Instructions')->schema([
                    Textarea::make('attendee_instructions')
                        ->label('Attendee instructions')
                        ->rows(6)
                        ->helperText(fn (): string => 'Leave empty to use the site-wide default: "'
                            .Str::limit((string) Setting::get('webinars.default_instructions', 'not set yet'), 120).'"'),
                ]),

                Tab::make('Media')->schema([
                    SpatieMediaLibraryFileUpload::make('card')
                        ->collection('card')
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['16:10'])
                        ->maxSize(4096)
                        ->helperText('Catalogue cards and the Similar Webinars rail.'),

                    SpatieMediaLibraryFileUpload::make('banner')
                        ->collection('banner')
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['3:1'])
                        ->maxSize(6144)
                        ->helperText('The full-width hero on the detail page. Title and badges sit over it, so avoid a busy left third.'),
                ])->columns(2),
            ]),
        ]);
    }

    /**
     * Renders the entered time the way the public page will — "26 Sep 2026 ·
     * 09:00 AM PST / 12:00 PM EST" — so whoever types it sees the customer's
     * view rather than trusting a picker.
     */
    private static function previewTime(mixed $at, mixed $timezone): string
    {
        if (blank($at)) {
            return 'Enter a date to preview how it will read.';
        }

        try {
            $zone = is_string($timezone) && $timezone !== '' ? $timezone : 'America/Los_Angeles';
            $moment = Carbon::parse((string) $at, $zone);

            return 'Customers see: '.$moment->format('j M Y')
                .' · '.$moment->format('h:i A').' '.$moment->format('T')
                .' / '.$moment->copy()->setTimezone('America/New_York')->format('h:i A T');
        } catch (Throwable) {
            return 'Enter a date to preview how it will read.';
        }
    }

    /** @return array<string, string> */
    private static function timezones(): array
    {
        return collect([
            'America/Los_Angeles', 'America/Denver', 'America/Chicago',
            'America/New_York', 'Europe/London', 'Asia/Kolkata', 'UTC',
        ])->mapWithKeys(static fn (string $zone): array => [$zone => $zone])->all();
    }
}
