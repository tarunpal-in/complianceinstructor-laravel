<?php

declare(strict_types=1);

namespace App\Filament\Pages\Schemas;

use App\Models\Industry;
use App\Models\Speaker;
use App\Models\Webinar;
use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/**
 * The homepage's block library — the "Homepage Sections" counterpart to
 * PageBlocks (CLAUDE.md §2).
 *
 * Kept separate from PageBlocks on purpose: these blocks are the homepage's
 * designed sections, not general-purpose page content, and the homepage editor
 * offers a fixed, curated set (the client reorders, edits and hides them, but
 * cannot add arbitrary ones). Each block is the editable half of a hand-styled
 * `components/home/*` component — the same §2 bargain, a block and a partial,
 * no model or table.
 *
 * Built one section at a time as the homepage is migrated onto the builder.
 * Today: the hero.
 */
class HomeBlocks
{
    /**
     * Icons the homepage sections may use — the names `x-ui.icon` actually knows.
     * An unknown name renders blank, so the picker is constrained to the set.
     *
     * @var array<int, string>
     */
    private const ICONS = [
        'award', 'calendar-days', 'check', 'clock-3', 'monitor', 'refresh-cw',
        'search', 'share-2', 'shield-check', 'shopping-cart', 'star',
        'user-round', 'users-round', 'zap',
    ];

    /** @return array<int, Builder\Block> */
    public static function all(): array
    {
        return [
            self::hero(),
            self::process(),
            self::webinars(),
            self::categories(),
            self::benefits(),
            self::faculty(),
            self::accreditation(),
            self::stories(),
            self::pricing(),
        ];
    }

    /** The banner at the top of the homepage — `components/home/hero`. */
    private static function hero(): Builder\Block
    {
        return Builder\Block::make('home_hero')
            ->label('Hero')
            ->icon(Heroicon::OutlinedSparkles)
            ->schema([
                TextInput::make('eyebrow')
                    ->label('Eyebrow')
                    ->maxLength(120)
                    ->helperText('The small line above the headline. A bullet is added automatically.'),

                Textarea::make('lede')
                    ->label('Opening paragraph')
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),

                // The headline is three fields because the comp styles the
                // middle phrase gold and forces a line break after it. Editing
                // it as one box would lose both.
                TextInput::make('heading_lead')
                    ->label('Headline — first part')
                    ->maxLength(120)
                    ->placeholder('Stay Ahead —'),

                TextInput::make('heading_highlight')
                    ->label('Headline — highlighted phrase')
                    ->maxLength(120)
                    ->placeholder('Learn from')
                    ->helperText('Shown in gold.'),

                TextInput::make('heading_tail')
                    ->label('Headline — last part')
                    ->maxLength(120)
                    ->placeholder('the Best.')
                    ->helperText('Starts on a new line.'),

                TextInput::make('primary_label')
                    ->label('Main button')
                    ->maxLength(60)
                    ->placeholder('Start Learning Now'),

                TextInput::make('secondary_label')
                    ->label('Second button')
                    ->maxLength(60)
                    ->placeholder('View Courses'),

                TextInput::make('secondary_url')
                    ->label('Second button links to')
                    ->maxLength(255)
                    ->placeholder('#webinars')
                    ->requiredWith('secondary_label'),

                Repeater::make('stats')
                    ->label('Headline figures')
                    ->minItems(1)
                    ->maxItems(3)
                    ->defaultItems(3)
                    ->columns(2)
                    ->itemLabel(fn (array $state): ?string => is_string($state['value'] ?? null) ? $state['value'] : null)
                    ->schema([
                        TextInput::make('value')->label('Figure')->required()->maxLength(30)->placeholder('48,000+'),
                        TextInput::make('label')->label('Caption')->required()->maxLength(80)->placeholder('Professionals Trained'),
                    ])
                    ->columnSpanFull(),

                Repeater::make('bodies')
                    ->label('“Recognized by” badges')
                    ->maxItems(6)
                    ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) ? $state['label'] : null)
                    ->schema([
                        TextInput::make('label')->hiddenLabel()->required()->maxLength(20)->placeholder('HRCI'),
                    ])
                    ->columnSpanFull(),

                self::visibility(),
            ])
            ->columns(2);
    }

    /** "How It Works" — `components/home/process`. */
    private static function process(): Builder\Block
    {
        return Builder\Block::make('home_process')
            ->label('How It Works')
            ->icon(Heroicon::OutlinedListBullet)
            ->schema([
                TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120)->placeholder('SIMPLE PROCESS'),
                TextInput::make('heading')->label('Heading')->maxLength(255)->placeholder('How It Works'),
                Textarea::make('intro')->label('Intro')->rows(2)->maxLength(400)->columnSpanFull(),

                Repeater::make('steps')
                    ->label('Steps')
                    ->minItems(1)
                    ->maxItems(4)
                    ->defaultItems(3)
                    ->itemLabel(fn (array $state): ?string => is_string($state['title'] ?? null) ? $state['title'] : null)
                    ->schema([
                        self::iconSelect(),
                        TextInput::make('title')->label('Title')->required()->maxLength(120),
                        Textarea::make('body')->label('Text')->rows(2)->maxLength(400)->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->helperText('Numbered automatically in the order shown.'),

                self::visibility(),
            ])
            ->columns(2);
    }

    /** The benefit strip — `components/home/benefits`. */
    private static function benefits(): Builder\Block
    {
        return Builder\Block::make('home_benefits')
            ->label('Benefits')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->schema([
                Repeater::make('benefits')
                    ->label('Benefits')
                    ->minItems(1)
                    ->maxItems(4)
                    ->defaultItems(3)
                    ->itemLabel(fn (array $state): ?string => is_string($state['title'] ?? null) ? $state['title'] : null)
                    ->schema([
                        self::iconSelect(),
                        TextInput::make('title')->label('Title')->required()->maxLength(120),
                        Textarea::make('body')->label('Text')->rows(2)->maxLength(400)->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                self::visibility(),
            ])
            ->columns(1);
    }

    /** "Success Stories" — `components/home/stories`. */
    private static function stories(): Builder\Block
    {
        return Builder\Block::make('home_stories')
            ->label('Success Stories')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->schema([
                TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120)->placeholder('WHAT PROFESSIONALS SAY'),
                TextInput::make('heading')->label('Heading')->maxLength(255)->placeholder('Success Stories'),

                TextInput::make('rating')->label('Rating shown')->maxLength(10)->placeholder('4.8'),
                TextInput::make('reviews')->label('Number of reviews')->numeric()->minValue(0)->placeholder('312'),

                Repeater::make('stories')
                    ->label('Testimonials')
                    ->minItems(1)
                    ->defaultItems(3)
                    ->itemLabel(fn (array $state): ?string => is_string($state['name'] ?? null) ? $state['name'] : null)
                    ->schema([
                        Textarea::make('quote')->label('Quote')->required()->rows(3)->maxLength(600)->columnSpanFull(),
                        TextInput::make('name')->label('Name')->required()->maxLength(120),
                        TextInput::make('role')->label('Role')->maxLength(160),
                        TextInput::make('initials')->label('Initials')->maxLength(4)->placeholder('SM'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                self::visibility(),
            ])
            ->columns(2);
    }

    /** "Upcoming Webinars" — live from the catalogue (`components/home/webinars`). */
    private static function webinars(): Builder\Block
    {
        return Builder\Block::make('home_webinars')
            ->label('Upcoming Webinars')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->schema([
                TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120)->placeholder('LIVE EVENTS'),
                TextInput::make('heading')->label('Heading')->maxLength(255)->placeholder('Upcoming Webinars'),
                Textarea::make('intro')->label('Intro')->rows(2)->maxLength(400)->columnSpanFull(),
                TextInput::make('link_label')->label('“See all” link text')->maxLength(60)->placeholder('All Upcoming Webinars'),
                TextInput::make('link_url')->label('“See all” link URL')->maxLength(255)->placeholder('#webinars'),

                ...self::source(
                    'upcoming webinars',
                    fn (): array => Webinar::query()->public()->orderByDesc('scheduled_at')->pluck('title', 'id')->all(),
                ),

                self::visibility(),
            ])
            ->columns(2);
    }

    /** "Webinar Categories" — live from industries (`components/home/categories`). */
    private static function categories(): Builder\Block
    {
        return Builder\Block::make('home_categories')
            ->label('Webinar Categories')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->schema([
                TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120)->placeholder('BROWSE BY INDUSTRY'),
                TextInput::make('heading')->label('Heading')->maxLength(255)->placeholder('Webinar Categories'),
                Textarea::make('intro')->label('Intro')->rows(2)->maxLength(400)->columnSpanFull(),

                ...self::source(
                    'industries',
                    fn (): array => Industry::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all(),
                ),

                self::visibility(),
            ])
            ->columns(2);
    }

    /** "Experienced Faculty" — live from speakers (`components/home/faculty`). */
    private static function faculty(): Builder\Block
    {
        return Builder\Block::make('home_faculty')
            ->label('Experienced Faculty')
            ->icon(Heroicon::OutlinedUsers)
            ->schema([
                TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120)->placeholder('OUR INSTRUCTORS'),
                TextInput::make('heading')->label('Heading')->maxLength(255)->placeholder('Experienced Faculty'),
                Textarea::make('intro')->label('Intro')->rows(3)->maxLength(600)->columnSpanFull(),
                TextInput::make('button_label')->label('Button')->maxLength(60)->placeholder('Meet the Speakers'),
                TextInput::make('button_url')->label('Button links to')->maxLength(255)->placeholder('#speakers'),

                ...self::source(
                    'speakers',
                    fn (): array => Speaker::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
                ),

                self::visibility(),
            ])
            ->columns(2);
    }

    /** "Accreditation Partners" — editable copy (`components/home/accreditation`). */
    private static function accreditation(): Builder\Block
    {
        return Builder\Block::make('home_accreditation')
            ->label('Accreditation Partners')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->schema([
                TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120)->placeholder('TRUSTED CREDENTIALS'),
                TextInput::make('heading')->label('Heading')->maxLength(255)->placeholder('Accreditation Partners'),
                Textarea::make('intro')->label('Intro')->rows(2)->maxLength(400)->columnSpanFull(),

                Repeater::make('partners')
                    ->label('Partners')
                    ->minItems(1)
                    ->maxItems(6)
                    ->defaultItems(3)
                    ->itemLabel(fn (array $state): ?string => is_string($state['code'] ?? null) ? $state['code'] : null)
                    ->schema([
                        TextInput::make('code')->label('Code')->required()->maxLength(20)->placeholder('HRCI'),
                        TextInput::make('name')->label('Full name')->maxLength(120),
                        Textarea::make('badge')->label('Badge line')->rows(2)->maxLength(120)->helperText('Two short lines; the break is kept.')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                self::visibility(),
            ])
            ->columns(2);
    }

    /** "Membership Plans" — the wrapper copy; plans stay live (`components/home/pricing`). */
    private static function pricing(): Builder\Block
    {
        return Builder\Block::make('home_pricing')
            ->label('Membership Plans')
            ->icon(Heroicon::OutlinedCreditCard)
            ->schema([
                TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120)->placeholder('FLEXIBLE PRICING'),
                TextInput::make('heading')->label('Heading')->maxLength(255)->placeholder('Membership Plans'),
                Textarea::make('intro')->label('Intro')->rows(2)->maxLength(400)->columnSpanFull(),

                TextInput::make('callback_heading')->label('Callback — heading')->maxLength(160),
                TextInput::make('callback_button')->label('Callback — button')->maxLength(60),
                Textarea::make('callback_body')->label('Callback — text')->rows(2)->maxLength(300)->columnSpanFull(),

                Placeholder::make('plans_note')
                    ->label('')
                    ->content('The plan cards are drawn from your Membership Plans automatically.')
                    ->columnSpanFull(),

                self::visibility(),
            ])
            ->columns(2);
    }

    /**
     * The "auto vs hand-picked" source fields shared by the live-data sections.
     *
     * Auto shows the newest from the catalogue (bounded by a limit); manual lets
     * the client pick exact records, in order. Either way the list is live — no
     * copy is duplicated into the block, so a renamed webinar is renamed here.
     *
     * @param  Closure(): array<int, string>  $options
     * @return array<int, \Filament\Forms\Components\Field>
     */
    private static function source(string $noun, Closure $options): array
    {
        return [
            Select::make('source')
                ->label('Which to show')
                ->options([
                    'auto' => 'Automatic — newest '.$noun,
                    'manual' => 'Hand-picked',
                ])
                ->default('auto')
                ->native(false)
                ->live(),

            TextInput::make('limit')
                ->label('How many to show')
                ->numeric()
                ->minValue(1)
                ->maxValue(12)
                ->default(3)
                ->visible(fn (callable $get): bool => $get('source') !== 'manual'),

            Select::make('items')
                ->label('Choose '.$noun)
                ->multiple()
                ->options($options)
                ->searchable()
                ->helperText('Shown in the order you add them.')
                ->visible(fn (callable $get): bool => $get('source') === 'manual')
                ->columnSpanFull(),
        ];
    }

    /** A constrained icon picker — only names `x-ui.icon` can render. */
    private static function iconSelect(): Select
    {
        return Select::make('icon')
            ->label('Icon')
            ->options(array_combine(self::ICONS, self::ICONS))
            ->searchable()
            ->native(false)
            ->placeholder('Choose an icon');
    }

    /**
     * On every block, lifted onto the row by PageForm — the same mechanism the
     * page builder uses, so "is a homepage section hidden" is a query too.
     */
    private static function visibility(): Toggle
    {
        return Toggle::make('is_visible')
            ->label('Show this section')
            ->default(true)
            ->helperText('Turn off to hide it without losing the copy.')
            ->columnSpanFull();
    }
}
