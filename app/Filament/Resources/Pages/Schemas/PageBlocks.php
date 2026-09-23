<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/**
 * The block library — CLAUDE.md §2's second leverage decision.
 *
 * "A new section type costs one Blade partial, not a full vertical slice."
 * Adding one here means a case in this file and a partial in
 * resources/views/pages/blocks. No model, no table, no controller, no route —
 * which is the whole ~5-week saving, and §14 #14 bans going back on it.
 *
 * Six types, per §13's descope ladder: "block library trimmed to six types,
 * rest added on demand". These six cover the pages §7 names — about, training
 * landings, contact, FAQ and four policy pages. The testimonial slider, logo
 * wall and webinar carousel from the full list are the ones to add first when
 * a page actually needs them, rather than now on the chance it might.
 *
 * Every block carries `is_visible`, because turning a block off beats deleting
 * it: seasonal copy comes back without being retyped.
 */
class PageBlocks
{
    /** @return array<int, Builder\Block> */
    public static function all(): array
    {
        return [
            self::richText(),
            self::hero(),
            self::featureGrid(),
            self::faq(),
            self::callToAction(),
            self::statBand(),
        ];
    }

    /**
     * Prose. The one every policy page is made of.
     */
    private static function richText(): Builder\Block
    {
        return Builder\Block::make('rich_text')
            ->label('Text')
            ->icon(Heroicon::OutlinedBars3BottomLeft)
            ->schema([
                TextInput::make('heading')
                    ->label('Heading')
                    ->maxLength(255)
                    ->placeholder('Optional'),

                RichEditor::make('body')
                    ->label('Text')
                    ->required()
                    // A deliberately short toolbar. The predecessor's pages
                    // carried inline styles pasted out of Word, and every one
                    // of them fought the site's own CSS.
                    ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'h3', 'blockquote', 'undo', 'redo'])
                    ->columnSpanFull(),

                self::visibility(),
            ])
            ->columns(1);
    }

    /** The band at the top of a landing page. */
    private static function hero(): Builder\Block
    {
        return Builder\Block::make('hero')
            ->label('Hero')
            ->icon(Heroicon::OutlinedSparkles)
            ->schema([
                TextInput::make('eyebrow')
                    ->label('Eyebrow')
                    ->maxLength(120)
                    ->placeholder('FLEXIBLE PRICING'),

                TextInput::make('heading')
                    ->label('Heading')
                    ->required()
                    ->maxLength(255),

                Textarea::make('lede')
                    ->label('Opening line')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),

                TextInput::make('button_label')
                    ->label('Button')
                    ->maxLength(60)
                    ->placeholder('Leave empty for no button'),

                TextInput::make('button_url')
                    ->label('Button links to')
                    ->maxLength(255)
                    ->placeholder('/webinars')
                    ->requiredWith('button_label'),

                self::visibility(),
            ])
            ->columns(2);
    }

    /** Three or four things the business wants to say at once. */
    private static function featureGrid(): Builder\Block
    {
        return Builder\Block::make('feature_grid')
            ->label('Feature grid')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->schema([
                TextInput::make('heading')->label('Heading')->maxLength(255),

                Textarea::make('intro')->label('Intro')->rows(2)->maxLength(500)->columnSpanFull(),

                Repeater::make('features')
                    ->label('Features')
                    ->minItems(1)
                    ->defaultItems(3)
                    ->columns(2)
                    ->itemLabel(fn (array $state): ?string => is_string($state['title'] ?? null) ? $state['title'] : null)
                    ->schema([
                        TextInput::make('title')->label('Title')->required()->maxLength(120),
                        Textarea::make('body')->label('Text')->rows(2)->maxLength(400)->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                self::visibility(),
            ])
            ->columns(1);
    }

    /** Questions and answers, which every policy and pricing page ends with. */
    private static function faq(): Builder\Block
    {
        return Builder\Block::make('faq')
            ->label('FAQ')
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->schema([
                TextInput::make('heading')->label('Heading')->maxLength(255)->default('Frequently asked questions'),

                Repeater::make('questions')
                    ->label('Questions')
                    ->minItems(1)
                    ->defaultItems(2)
                    ->itemLabel(fn (array $state): ?string => is_string($state['question'] ?? null) ? $state['question'] : null)
                    ->schema([
                        TextInput::make('question')->label('Question')->required()->maxLength(255),
                        Textarea::make('answer')->label('Answer')->required()->rows(3)->maxLength(1000),
                    ])
                    ->columnSpanFull(),

                self::visibility(),
            ])
            ->columns(1);
    }

    /** The ask. */
    private static function callToAction(): Builder\Block
    {
        return Builder\Block::make('cta')
            ->label('Call to action')
            ->icon(Heroicon::OutlinedMegaphone)
            ->schema([
                TextInput::make('heading')->label('Heading')->required()->maxLength(255),

                Textarea::make('body')->label('Text')->rows(2)->maxLength(400)->columnSpanFull(),

                TextInput::make('button_label')->label('Button')->required()->maxLength(60),

                TextInput::make('button_url')->label('Button links to')->required()->maxLength(255)->placeholder('/webinars'),

                Select::make('tone')
                    ->label('Emphasis')
                    ->options([
                        'primary' => 'Bold — for the main ask on a page',
                        'quiet' => 'Quiet — for a secondary nudge',
                    ])
                    ->default('primary'),

                self::visibility(),
            ])
            ->columns(2);
    }

    /** Numbers the business leads with. */
    private static function statBand(): Builder\Block
    {
        return Builder\Block::make('stat_band')
            ->label('Statistics')
            ->icon(Heroicon::OutlinedChartBar)
            ->schema([
                Repeater::make('stats')
                    ->label('Figures')
                    ->minItems(1)
                    ->maxItems(4)
                    ->defaultItems(3)
                    ->columns(2)
                    ->itemLabel(fn (array $state): ?string => is_string($state['value'] ?? null) ? $state['value'] : null)
                    ->schema([
                        TextInput::make('value')->label('Figure')->required()->maxLength(30)->placeholder('48,000+'),
                        TextInput::make('label')->label('Caption')->required()->maxLength(80)->placeholder('Professionals trained'),
                    ])
                    ->columnSpanFull()
                    // Four is where a stat band stops reading as a band and
                    // starts reading as a table.
                    ->helperText('Up to four. Three is usually the right number.'),

                self::visibility(),
            ])
            ->columns(1);
    }

    /**
     * On every block, and stored on the row rather than inside `data`.
     *
     * PageForm lifts it out on save, which is what lets "which pages have a
     * hidden block" be a query rather than a scan through JSON.
     */
    private static function visibility(): Toggle
    {
        return Toggle::make('is_visible')
            ->label('Show this block')
            ->default(true)
            ->helperText('Turn off to hide it without losing the copy.')
            ->columnSpanFull();
    }
}
