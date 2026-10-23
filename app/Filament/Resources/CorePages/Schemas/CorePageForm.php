<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorePages\Schemas;

use App\Enums\CorePageKey;
use App\Filament\Forms\Components\MediaPicker;
use App\Filament\Forms\Components\SeoFields;
use App\Models\CorePage;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The hero, membership CTA, copy blocks and SEO for a core page.
 *
 * One column of stacked cards (§ the admin layout convention). The sections
 * repeater and the SEO panel both save through `->relationship()`, so no manual
 * fill/save glue is needed on the edit page.
 */
class CorePageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Hero')
                ->description('The banner at the top of the page.')
                ->columns(2)
                ->schema([
                    TextInput::make('hero_eyebrow')
                        ->label('Eyebrow')
                        ->maxLength(120)
                        ->helperText('The small upper-case line above the title.'),

                    TextInput::make('hero_title')
                        ->label('Title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Textarea::make('hero_subtitle')
                        ->label('Subtitle')
                        ->rows(3)
                        ->columnSpanFull(),

                    MediaPicker::make('hero_media_id')
                        ->label('Background image')
                        ->helperText('Optional. Picked from the media library; replacing the file updates every page that uses it.'),

                    Toggle::make('hero_overlay')
                        ->label('Darken the background')
                        ->helperText('Keeps the hero text readable over a photo.')
                        ->default(true),
                ]),

            Section::make('"Request a Callback" button')
                ->description('The help button under the plan cards on the membership page. Leave the link blank to show a "we\'ll be in touch" message instead of a hyperlink.')
                // Only the membership page carries this CTA.
                ->visible(fn (?CorePage $record): bool => $record?->key === CorePageKey::Membership)
                ->columns(2)
                ->schema([
                    TextInput::make('cta_label')
                        ->label('Button label')
                        ->maxLength(60)
                        ->placeholder('Request a Callback'),

                    TextInput::make('cta_url')
                        ->label('Button link')
                        ->maxLength(255)
                        ->placeholder('/contact'),
                ]),

            Section::make('Content')
                ->description('Extra copy blocks shown on the page, around its main content. Leave a heading blank for an untitled block.')
                ->schema([
                    Repeater::make('sections')
                        ->relationship()
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('heading')
                                ->label('Section heading')
                                ->placeholder('Leave blank for an untitled block')
                                ->maxLength(255),

                            RichEditor::make('body')
                                ->label('Content')
                                ->toolbarButtons([
                                    ['bold', 'italic', 'underline', 'strike', 'link'],
                                    ['h2', 'h3'],
                                    ['bulletList', 'orderedList'],
                                    ['blockquote'],
                                    ['undo', 'redo'],
                                ])
                                ->required(),

                            Toggle::make('is_visible')
                                ->label('Show on the page')
                                ->default(true),
                        ])
                        ->orderColumn('sort_order')
                        ->reorderable()
                        ->collapsible()
                        ->collapsed()
                        ->cloneable()
                        ->itemLabel(fn (array $state): string => filled($state['heading'] ?? null) ? $state['heading'] : 'Untitled block')
                        ->addActionLabel('Add section')
                        ->defaultItems(0),
                ]),

            Section::make('Frequently asked questions')
                ->description('Shown as an accordion on the page. Leave empty to hide it.')
                ->schema([
                    Repeater::make('faq')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('question')->required()->maxLength(255),
                            Textarea::make('answer')->required()->rows(3),
                        ])
                        ->reorderable()
                        ->collapsible()
                        ->collapsed()
                        ->itemLabel(fn (array $state): string => filled($state['question'] ?? null) ? $state['question'] : 'Question')
                        ->addActionLabel('Add question')
                        ->defaultItems(0),
                ]),

            SeoFields::make(),
        ]);
    }
}
