<?php

declare(strict_types=1);

namespace App\Filament\Resources\Industries\Schemas;

use App\Filament\Forms\Components\SeoFields;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class IndustryForm
{
    public static function configure(Schema $schema): Schema
    {
        // One column, so each section stacks full-width down the page instead
        // of tiling two-up. Field pairs inside a section are kept.
        return $schema->columns(1)->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
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
                        // save and reported, rather than refusing the save and
                        // leaving the client to find who holds the name.
                        // Second segment of a webinar's breadcrumb, so it is
                        // part of the public URL.
                        ->helperText('Appears in webinar URLs: /live-webinars/{slug}/…'),

                    Textarea::make('description')
                        ->rows(3)
                        ->columnSpanFull(),

                    TextInput::make('sort_order')
                        ->numeric()
                        ->default(0)
                        ->helperText('Lower numbers appear first on the homepage.'),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ]),

            Section::make('Card image')
                ->schema([
                    SpatieMediaLibraryFileUpload::make('card')
                        ->collection('card')
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['3:2'])
                        ->maxSize(4096)
                        ->helperText('Used on the homepage category cards. White text sits over it, so choose something with a dark or busy lower third.')
                        ->hiddenLabel(),
                ]),
            SeoFields::make(),

        ]);
    }
}
