<?php

declare(strict_types=1);

namespace App\Filament\Resources\Speakers\Schemas;

use App\Filament\Forms\Components\SeoFields;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SpeakerForm
{
    public static function configure(Schema $schema): Schema
    {
        // One column, so each section stacks full-width down the page instead
        // of tiling two-up. Field pairs inside a section (its own ->columns)
        // are kept.
        return $schema->columns(1)->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        // Only auto-fill on create. Rewriting the slug of a
                        // speaker who already has a public profile would break
                        // every inbound link to it (§3 — the business lives on
                        // SEO).
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
                        ->helperText('Used in the speaker profile URL.'),

                    TextInput::make('title')
                        ->label('Job title')
                        ->maxLength(255)
                        ->helperText('Shown under their name — "HR Compliance Specialist".'),

                    TextInput::make('credentials')
                        ->label('Credentials')
                        ->maxLength(120)
                        // Separate from the job title because they answer
                        // different questions, and a CEU buyer is mostly asking
                        // this one.
                        ->helperText('Post-nominals, shown highlighted under the role — "CPA · SHRM-SCP".'),

                    TextInput::make('linkedin_url')
                        ->label('LinkedIn')
                        ->url()
                        ->maxLength(255),

                    Textarea::make('bio')
                        ->rows(6)
                        ->columnSpanFull()
                        ->helperText('Their credentials are most of what a CEU buyer is paying for.'),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive speakers stay attached to past webinars but are not listed publicly.'),
                ]),

            Section::make('Profile page')
                ->description('Shown on the public speaker profile. The webinar counts, enrolment, topic domains and credits are derived automatically — these are the figures that are not.')
                ->columns(3)
                ->schema([
                    TextInput::make('rating')
                        ->label('Overall rating')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(5)
                        ->step(0.1)
                        ->suffix('/ 5')
                        // Hand-entered like the homepage stat band: there is no
                        // reviews system to derive it from, so leaving it empty
                        // omits the row rather than showing a made-up score.
                        ->helperText('Leave empty to hide the rating.'),

                    TextInput::make('reviews_count')
                        ->label('Total reviews')
                        ->numeric()
                        ->minValue(0),

                    TextInput::make('years_teaching')
                        ->label('Years teaching')
                        ->numeric()
                        ->minValue(0),

                    TagsInput::make('expertise')
                        ->label('Areas of expertise')
                        ->placeholder('Add a specialty')
                        ->columnSpanFull()
                        ->helperText('Shown as tags under the bio — "BSA/AML Compliance", "Fraud Prevention".'),
                ]),

            Section::make('Portrait')
                ->schema([
                    SpatieMediaLibraryFileUpload::make('portrait')
                        ->collection('portrait')
                        ->image()
                        ->imageEditor()
                        // The faculty grid crops 1:1; editing here rather than
                        // hoping the upload is already square.
                        ->imageEditorAspectRatios(['1:1'])
                        ->maxSize(4096)
                        ->helperText('Square. Cropped to 254x254 for the faculty grid.')
                        ->hiddenLabel(),
                ]),
            SeoFields::make(),

        ]);
    }
}
