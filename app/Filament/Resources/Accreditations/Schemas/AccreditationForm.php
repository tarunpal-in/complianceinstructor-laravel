<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accreditations\Schemas;

use App\Support\CompletionRules;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccreditationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(20)
                        ->unique(ignoreRecord: true)
                        ->helperText('The short form shown on webinar cards — HRCI, SHRM, CPE, APA.'),

                    TextInput::make('name')
                        ->label('Full name')
                        ->required()
                        ->maxLength(255),

                    Textarea::make('description')
                        ->rows(3)
                        ->columnSpanFull(),

                    SpatieMediaLibraryFileUpload::make('logo')
                        ->collection('logo')
                        ->image()
                        ->imageEditor()
                        ->maxSize(2048)
                        ->columnSpanFull()
                        ->helperText('The body\'s logo, shown on the homepage "Accreditation Partners" section. Upload a licensed or official mark.'),

                    TextInput::make('sort_order')->numeric()->default(0),

                    Toggle::make('is_active')->label('Active')->default(true),
                ]),

            /*
             * §12 — "keep thresholds configurable per accrediting body, never
             * hardcoded". Both are optional, and the placeholder shows what
             * they inherit, so leaving them blank is an informed choice rather
             * than a blank somebody is unsure about.
             */
            Section::make('Certificate rules')
                ->description('Only fill these in where this body requires something different from everybody else. Blank means it follows the defaults in Settings.')
                ->columns(2)
                ->schema([
                    TextInput::make('live_delay_hours')
                        ->label('Issue live certificates after')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(672)
                        ->suffix('hours')
                        ->placeholder(fn (): string => CompletionRules::liveDelayHours().' (from Settings)')
                        ->helperText('How long after a live session this body\'s certificates go out.'),

                    TextInput::make('watch_tolerance_minutes')
                        ->label('Allow skipping')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(60)
                        ->suffix('minutes')
                        ->placeholder(fn (): string => CompletionRules::watchToleranceMinutes().' (from Settings)')
                        // The case this exists for: a body demanding five
                        // minutes where the site allows ten means one webinar
                        // awards one body's credit and not the other's.
                        ->helperText('How much of a recording may be left unwatched and still earn this body\'s credit.'),
                ]),
        ]);
    }
}
