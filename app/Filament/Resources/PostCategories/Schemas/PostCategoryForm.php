<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostCategories\Schemas;

use App\Filament\Forms\Components\SeoFields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PostCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        // One column, so each section stacks full-width down the page instead
        // of tiling two-up. Field pairs inside a section are kept.
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(120)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                        if ($operation === 'create') {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    // No unique rule: a clash resolves to "{slug}-2" on save.
                    ->helperText('Used in the blog\'s category links.'),

                Textarea::make('description')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull()
                    ->helperText('Shown at the top of the category listing.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->helperText('Lowest first.'),

                Toggle::make('is_active')
                    ->label('Available')
                    ->default(true)
                    // Switching it off hides the category without touching the
                    // articles in it — the same reasoning as a hidden block.
                    ->helperText('Turning this off hides the category. Its articles stay published.'),
            ]),
            SeoFields::make(),

        ]);
    }
}
