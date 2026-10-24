<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Forms\Components\MediaPicker;
use Filament\Actions\Action;
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
 * Started at six per §13's descope ladder ("block library trimmed to six
 * types, rest added on demand"); `contact` is the first of the "on demand"
 * ones, added when the contact page needed a form. Together they cover the
 * pages §7 names — about, training landings, contact, FAQ and four policy
 * pages. The testimonial slider, logo wall and webinar carousel from the full
 * list are the ones to add next when a page actually needs them.
 *
 * Every block carries `is_visible`, because turning a block off beats deleting
 * it: seasonal copy comes back without being retyped.
 */
class PageBlocks
{
    /**
     * Which block types can be turned into which, without losing content.
     *
     * A conversion is lossless when the target block shares the source's field
     * names: `media_text` is a deliberate superset of `rich_text` (same
     * `heading` and `body`, plus an image), so the two convert either way. The
     * whole data array is kept on convert — shared fields carry straight over,
     * and any field the target does not use lies dormant rather than being
     * dropped, so converting back restores it. Extend this map as more blocks
     * grow compatible fields.
     *
     * @var array<string, array<string, string>> from => [to => menu label]
     */
    private const CONVERSIONS = [
        'rich_text' => ['media_text' => 'Add an image (Text with image)'],
        'media_text' => ['rich_text' => 'Remove the image (plain Text)'],
    ];

    /** @return array<int, Builder\Block> */
    public static function all(): array
    {
        return [
            self::richText(),
            self::mediaText(),
            self::hero(),
            self::featureGrid(),
            self::faq(),
            self::callToAction(),
            self::statBand(),
            self::contact(),
        ];
    }

    /**
     * Per-block "convert to…" actions for the builder's item menu.
     *
     * Each pair in CONVERSIONS becomes one icon action that shows only on a
     * block of the matching source type and swaps that one item's type in place,
     * keeping its data. PageForm hands these to the Builder's extraItemActions().
     *
     * @return array<int, Action>
     */
    public static function convertActions(): array
    {
        $actions = [];

        foreach (self::CONVERSIONS as $from => $targets) {
            foreach ($targets as $to => $label) {
                $actions[] = self::convertAction($from, $to, $label);
            }
        }

        return $actions;
    }

    /**
     * Swap one builder item's type, keeping its data — the lossless core, pure
     * so it is unit-tested without the Livewire form around it.
     *
     * @param  array<int|string, mixed>  $items  the builder's raw state
     * @return array<int|string, mixed>
     */
    public static function convertItem(array $items, int|string $key, string $to): array
    {
        if (isset($items[$key]) && is_array($items[$key])) {
            $items[$key]['type'] = $to;
        }

        return $items;
    }

    private static function convertAction(string $from, string $to, string $label): Action
    {
        return Action::make('convert_'.$from.'_to_'.$to)
            ->label($label)
            ->tooltip($label)
            ->icon($to === 'media_text' ? Heroicon::OutlinedPhoto : Heroicon::OutlinedBars3BottomLeft)
            ->color('gray')
            // Only on a block that is currently the source type.
            ->visible(fn (array $arguments, Builder $component): bool => data_get($component->getRawState(), $arguments['item'].'.type') === $from)
            ->action(function (array $arguments, Builder $component) use ($to): void {
                $component->rawState(self::convertItem($component->getRawState(), $arguments['item'], $to));
                $component->callAfterStateUpdated();
                $component->partiallyRender();
            });
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

    /**
     * Prose beside a picture — an "Our mission" or "Who we serve" section, a
     * feature explained next to a photo. The image sits left or right of the
     * text; with no image it falls back to full-width prose.
     */
    private static function mediaText(): Builder\Block
    {
        return Builder\Block::make('media_text')
            ->label('Text with image')
            ->icon(Heroicon::OutlinedPhoto)
            ->schema([
                TextInput::make('heading')
                    ->label('Heading')
                    ->maxLength(255)
                    ->placeholder('Optional'),

                RichEditor::make('body')
                    ->label('Text')
                    ->required()
                    ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'h3', 'blockquote', 'undo', 'redo'])
                    ->columnSpanFull(),

                MediaPicker::make('image')
                    ->label('Image')
                    ->helperText('Pick from the Media library — upload it there first if it is not listed. Shown beside the text. Leave empty for full-width text.'),

                Select::make('image_position')
                    ->label('Image position')
                    ->options([
                        'right' => 'Right of the text',
                        'left' => 'Left of the text',
                    ])
                    ->default('right')
                    ->native(false),

                TextInput::make('image_alt')
                    ->label('Image description')
                    ->maxLength(255)
                    // WCAG AA (§9): a marketing image still needs a text alternative
                    // for screen readers and for when it fails to load.
                    ->helperText('Describes the image for screen readers and if it cannot load.'),

                self::visibility(),
            ])
            ->columns(2);
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
                        self::iconSelect(),
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
     * A contact section — editable detail cards beside a working contact form.
     *
     * The form itself is the ContactForm Livewire component (it records an
     * inquiry through RecordInquiry); everything a visitor reads here is editable
     * copy, so a contact page assembled from this block is 100% client-editable.
     */
    private static function contact(): Builder\Block
    {
        return Builder\Block::make('contact')
            ->label('Contact')
            ->icon(Heroicon::OutlinedEnvelope)
            ->schema([
                TextInput::make('heading')->label('Heading')->maxLength(255)->default('Contact us'),

                Textarea::make('intro')->label('Intro')->rows(2)->maxLength(500)->columnSpanFull(),

                Repeater::make('details')
                    ->label('Contact details')
                    ->helperText('Shown as cards beside the form — email, phone, address, hours, and so on.')
                    ->defaultItems(3)
                    ->maxItems(8)
                    ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) ? $state['label'] : null)
                    ->schema([
                        Select::make('icon')
                            ->label('Icon')
                            ->options([
                                'mail' => 'Email',
                                'phone' => 'Phone',
                                'map-pin' => 'Address',
                                'clock-3' => 'Hours',
                                'info' => 'Info',
                                'linkedin' => 'LinkedIn',
                                'instagram' => 'Instagram',
                                'youtube' => 'YouTube',
                            ])
                            ->default('mail')
                            ->native(false),

                        TextInput::make('label')->label('Label')->required()->maxLength(60)->placeholder('Email'),
                        TextInput::make('value')->label('Value')->required()->maxLength(200)->placeholder('hello@complianceinstructor.com'),
                        TextInput::make('url')->label('Link (optional)')->maxLength(255)->placeholder('mailto:hello@complianceinstructor.com'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                TextInput::make('form_heading')->label('Form heading')->maxLength(120)->default('Send us a message'),
                TextInput::make('button_label')->label('Form button')->maxLength(60)->default('Send message'),
                Textarea::make('success_message')
                    ->label('Message shown after sending')
                    ->rows(2)
                    ->maxLength(300)
                    ->default('Thanks — your message is on its way. We will be in touch shortly.')
                    ->columnSpanFull(),

                self::visibility(),
            ])
            ->columns(2);
    }

    /**
     * A constrained icon picker — only names `x-ui.icon` can render. Optional on
     * a feature card: left blank, the card simply shows no icon.
     */
    private static function iconSelect(): Select
    {
        return Select::make('icon')
            ->label('Icon')
            ->options([
                'award' => 'Award / credit',
                'shield-check' => 'Shield / trust',
                'check' => 'Check',
                'users-round' => 'People',
                'user-round' => 'Person',
                'star' => 'Star',
                'zap' => 'Lightning',
                'refresh-cw' => 'Refresh',
                'clock-3' => 'Clock',
                'calendar-days' => 'Calendar',
                'monitor' => 'Screen',
                'shopping-cart' => 'Cart',
                'search' => 'Search',
                'info' => 'Info',
            ])
            ->native(false)
            ->placeholder('No icon');
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
