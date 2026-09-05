<?php

declare(strict_types=1);

namespace App\Filament\Resources\Speakers\Schemas;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
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
        return $schema->components([
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
                        ->unique(ignoreRecord: true)
                        ->helperText('Used in the speaker profile URL.'),

                    TextInput::make('title')
                        ->label('Job title')
                        ->maxLength(255)
                        ->helperText('Shown under their name — "HR Compliance Specialist".'),

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
        ]);
    }
}
