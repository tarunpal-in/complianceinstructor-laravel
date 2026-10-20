<?php

declare(strict_types=1);

namespace App\Filament\Pages\Schemas;

use App\Models\Industry;
use App\Models\Speaker;
use App\Models\Webinar;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Support\Icons\Heroicon;

/**
 * The homepage's section library — the "Homepage Sections" counterpart to
 * PageBlocks (CLAUDE.md §2).
 *
 * Kept separate from PageBlocks on purpose: these are the homepage's designed
 * sections, not general-purpose page content, and the editor offers a fixed,
 * curated set (the client reorders, edits and hides them, but cannot add
 * arbitrary ones). Each section is the editable half of a hand-styled
 * `components/home/*` component — the same §2 bargain, a definition and a
 * partial, no model or table.
 *
 * The editor (HomepageSections) renders these as a tab per section plus an
 * "Arrange" tab for order and visibility, so this exposes each section's field
 * schema and its label/icon rather than a Builder block.
 */
class HomeBlocks
{
    /**
     * Icons the step/benefit pickers may use — the names `x-ui.icon` actually
     * knows. An unknown name renders blank, so the picker is constrained.
     *
     * @var array<int, string>
     */
    private const ICONS = [
        'award', 'calendar-days', 'check', 'clock-3', 'monitor', 'refresh-cw',
        'search', 'share-2', 'shield-check', 'shopping-cart', 'star',
        'user-round', 'users-round', 'zap',
    ];

    /**
     * Every homepage section, in the order its editing tab appears.
     *
     * @return list<array{type: string, label: string, icon: Heroicon, schema: array<int, mixed>}>
     */
    public static function definitions(): array
    {
        return [
            ['type' => 'home_hero', 'label' => 'Hero', 'icon' => Heroicon::OutlinedSparkles, 'schema' => self::heroSchema()],
            ['type' => 'home_process', 'label' => 'How It Works', 'icon' => Heroicon::OutlinedListBullet, 'schema' => self::processSchema()],
            ['type' => 'home_webinars', 'label' => 'Upcoming Webinars', 'icon' => Heroicon::OutlinedCalendarDays, 'schema' => self::webinarsSchema()],
            ['type' => 'home_categories', 'label' => 'Webinar Categories', 'icon' => Heroicon::OutlinedSquares2x2, 'schema' => self::categoriesSchema()],
            ['type' => 'home_benefits', 'label' => 'Benefits', 'icon' => Heroicon::OutlinedCheckBadge, 'schema' => self::benefitsSchema()],
            ['type' => 'home_faculty', 'label' => 'Experienced Faculty', 'icon' => Heroicon::OutlinedUsers, 'schema' => self::facultySchema()],
            ['type' => 'home_accreditation', 'label' => 'Accreditation Partners', 'icon' => Heroicon::OutlinedShieldCheck, 'schema' => self::accreditationSchema()],
            ['type' => 'home_stories', 'label' => 'Success Stories', 'icon' => Heroicon::OutlinedChatBubbleLeftRight, 'schema' => self::storiesSchema()],
            ['type' => 'home_pricing', 'label' => 'Membership Plans', 'icon' => Heroicon::OutlinedCreditCard, 'schema' => self::pricingSchema()],
        ];
    }

    /**
     * Section type => human label, for the "Arrange" list and tab titles.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::definitions() as $definition) {
            $labels[$definition['type']] = $definition['label'];
        }

        return $labels;
    }

    /** @return list<string> */
    public static function knownTypes(): array
    {
        return array_keys(self::labels());
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::knownTypes(), true);
    }

    /**
     * The banner at the top of the homepage — `components/home/hero`.
     *
     * @return array<int, mixed>
     */
    private static function heroSchema(): array
    {
        return [
            Fieldset::make('Headline & intro')
                ->columns(2)
                ->schema([
                    TextInput::make('eyebrow')
                        ->label('Eyebrow')
                        ->maxLength(120)
                        ->helperText('The small line above the headline. A bullet is added automatically.')
                        ->columnSpanFull(),

                    Textarea::make('lede')
                        ->label('Opening paragraph')
                        ->rows(3)
                        ->maxLength(500)
                        ->columnSpanFull(),

                    // The headline is three fields because the comp styles the
                    // middle phrase gold and forces a line break after it.
                    // Editing it as one box would lose both.
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
                        ->helperText('Starts on a new line.')
                        ->columnSpanFull(),
                ]),

            Fieldset::make('Buttons')
                ->columns(2)
                ->schema([
                    TextInput::make('primary_label')
                        ->label('Main button')
                        ->maxLength(60)
                        ->placeholder('Start Learning Now')
                        ->columnSpanFull(),

                    TextInput::make('secondary_label')
                        ->label('Second button')
                        ->maxLength(60)
                        ->placeholder('View Courses'),

                    TextInput::make('secondary_url')
                        ->label('Second button links to')
                        ->maxLength(255)
                        ->placeholder('#webinars')
                        ->requiredWith('secondary_label'),
                ]),

            Fieldset::make('Hero image')
                ->schema([
                    FileUpload::make('images')
                        ->hiddenLabel()
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->disk('public')
                        ->directory('homepage')
                        ->visibility('public')
                        ->imageEditor()
                        ->maxSize(5120)
                        ->maxFiles(6)
                        ->helperText('One image shows on its own; add several to rotate them as a slider (drag to set the order). Leave empty to use the default. Recommended ~940×628.')
                        ->columnSpanFull(),
                ]),

            Fieldset::make('Headline figures')
                ->schema([
                    Repeater::make('stats')
                        ->hiddenLabel()
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
                ]),

            Fieldset::make('“Recognized by” badges')
                ->schema([
                    Repeater::make('bodies')
                        ->hiddenLabel()
                        ->maxItems(6)
                        ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) ? $state['label'] : null)
                        ->schema([
                            TextInput::make('label')->hiddenLabel()->required()->maxLength(20)->placeholder('HRCI'),
                        ])
                        ->columnSpanFull(),
                ]),

            Fieldset::make('Floating cards on the hero image')
                ->columns(2)
                ->schema([
                    // The floating "Certificate Ready" card cycles through these;
                    // one on its own simply does not rotate.
                    Repeater::make('feature_cards')
                        ->label('Rotating feature cards')
                        ->helperText('The badge that rotates on the hero image (top-left). Each line is shown for a few seconds in turn.')
                        ->maxItems(8)
                        ->defaultItems(4)
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string => is_string($state['title'] ?? null) ? $state['title'] : null)
                        ->schema([
                            TextInput::make('title')->label('Title')->required()->maxLength(40)->placeholder('Certificate Ready'),
                            TextInput::make('subtitle')->label('Subtitle')->required()->maxLength(60)->placeholder('Instant download'),
                        ])
                        ->columnSpanFull(),

                    // The "Approved" card rotates through the active accrediting
                    // bodies; these two fields are the wording around each code.
                    TextInput::make('accreditation_suffix')
                        ->label('Accreditation card — word after the code')
                        ->maxLength(30)
                        ->placeholder('Approved')
                        ->helperText('Shown as “HRCI Approved”, “SHRM Approved”, …'),

                    TextInput::make('accreditation_note')
                        ->label('Accreditation card — subtitle')
                        ->maxLength(60)
                        ->placeholder('Industry Accredited'),
                ]),
        ];
    }

    /**
     * "How It Works" — `components/home/process`.
     *
     * @return array<int, mixed>
     */
    private static function processSchema(): array
    {
        return [
            self::headingFieldset('SIMPLE PROCESS', 'How It Works'),

            Fieldset::make('Steps')
                ->schema([
                    Repeater::make('steps')
                        ->hiddenLabel()
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
                ]),
        ];
    }

    /**
     * The benefit strip — `components/home/benefits`.
     *
     * @return array<int, mixed>
     */
    private static function benefitsSchema(): array
    {
        return [
            Fieldset::make('Benefits')
                ->schema([
                    Repeater::make('benefits')
                        ->hiddenLabel()
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
                ]),
        ];
    }

    /**
     * "Success Stories" — `components/home/stories`.
     *
     * @return array<int, mixed>
     */
    private static function storiesSchema(): array
    {
        return [
            self::headingFieldset('WHAT PROFESSIONALS SAY', 'Success Stories', withIntro: false),

            Fieldset::make('Rating summary')
                ->columns(2)
                ->schema([
                    TextInput::make('rating')->label('Rating shown')->maxLength(10)->placeholder('4.8'),
                    TextInput::make('reviews')->label('Number of reviews')->numeric()->minValue(0)->placeholder('312'),
                ]),

            Fieldset::make('Testimonials')
                ->schema([
                    Repeater::make('stories')
                        ->hiddenLabel()
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
                ]),
        ];
    }

    /**
     * "Upcoming Webinars" — live from the catalogue (`components/home/webinars`).
     *
     * @return array<int, mixed>
     */
    private static function webinarsSchema(): array
    {
        return [
            self::headingFieldset('LIVE EVENTS', 'Upcoming Webinars'),

            Fieldset::make('“See all” link')
                ->columns(2)
                ->schema([
                    TextInput::make('link_label')->label('Link text')->maxLength(60)->placeholder('All Upcoming Webinars'),
                    TextInput::make('link_url')->label('Link URL')->maxLength(255)->placeholder('/webinars'),
                ]),

            self::source(
                'upcoming webinars',
                fn (): array => Webinar::query()->public()->orderByDesc('scheduled_at')->pluck('title', 'id')->all(),
            ),
        ];
    }

    /**
     * "Webinar Categories" — live from industries (`components/home/categories`).
     *
     * @return array<int, mixed>
     */
    private static function categoriesSchema(): array
    {
        return [
            self::headingFieldset('BROWSE BY INDUSTRY', 'Webinar Categories'),

            Fieldset::make('Display style')
                ->columns(2)
                ->schema([
                    // Grid (one row) or a scroll-snap slider with prev/next arrows
                    // — pick the slider when there are more categories than fit in
                    // a single row.
                    Select::make('layout')
                        ->label('Layout')
                        ->options(['grid' => 'Grid', 'slider' => 'Slider'])
                        ->default('grid')
                        ->native(false)
                        ->live(),

                    Toggle::make('autoplay')
                        ->label('Auto-advance the slider')
                        ->helperText('The slider moves on its own, pausing while the visitor hovers it.')
                        ->visible(fn (callable $get): bool => $get('layout') === 'slider'),
                ]),

            self::source(
                'industries',
                fn (): array => Industry::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all(),
            ),
        ];
    }

    /**
     * "Experienced Faculty" — live from speakers (`components/home/faculty`).
     *
     * @return array<int, mixed>
     */
    private static function facultySchema(): array
    {
        return [
            self::headingFieldset('OUR INSTRUCTORS', 'Experienced Faculty'),

            Fieldset::make('Button')
                ->columns(2)
                ->schema([
                    TextInput::make('button_label')->label('Button text')->maxLength(60)->placeholder('Meet the Speakers'),
                    TextInput::make('button_url')->label('Button links to')->maxLength(255)->placeholder('#speakers'),
                ]),

            self::source(
                'speakers',
                fn (): array => Speaker::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            ),
        ];
    }

    /**
     * "Accreditation Partners" — editable copy (`components/home/accreditation`).
     *
     * @return array<int, mixed>
     */
    private static function accreditationSchema(): array
    {
        return [
            self::headingFieldset('TRUSTED CREDENTIALS', 'Accreditation Partners'),

            Fieldset::make('Partners')
                ->schema([
                    Repeater::make('partners')
                        ->hiddenLabel()
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
                ]),
        ];
    }

    /**
     * "Membership Plans" — the wrapper copy; plans stay live
     * (`components/home/pricing`).
     *
     * @return array<int, mixed>
     */
    private static function pricingSchema(): array
    {
        return [
            self::headingFieldset('FLEXIBLE PRICING', 'Membership Plans'),

            Fieldset::make('Callback box')
                ->columns(2)
                ->schema([
                    TextInput::make('callback_heading')->label('Heading')->maxLength(160),
                    TextInput::make('callback_button')->label('Button')->maxLength(60),
                    TextInput::make('callback_url')
                        ->label('Button links to')
                        ->maxLength(255)
                        ->placeholder('/contact')
                        ->helperText('Where the button goes — e.g. /contact or a mailto: link. Leave empty for just a confirmation message.')
                        ->columnSpanFull(),
                    Textarea::make('callback_body')->label('Text')->rows(2)->maxLength(300)->columnSpanFull(),
                ]),

            Placeholder::make('plans_note')
                ->label('')
                ->content('The plan cards are drawn from your Membership Plans automatically.')
                ->columnSpanFull(),
        ];
    }

    /**
     * The eyebrow + heading + (optional) intro trio most sections share, grouped
     * into one titled fieldset so the heading copy reads as one cluster.
     */
    private static function headingFieldset(string $eyebrow, string $heading, bool $withIntro = true): Fieldset
    {
        $fields = [
            TextInput::make('eyebrow')->label('Eyebrow')->maxLength(120)->placeholder($eyebrow),
            TextInput::make('heading')->label('Heading')->maxLength(255)->placeholder($heading),
        ];

        if ($withIntro) {
            $fields[] = Textarea::make('intro')->label('Intro')->rows(2)->maxLength(400)->columnSpanFull();
        }

        return Fieldset::make('Heading & intro')->columns(2)->schema($fields);
    }

    /**
     * The "auto vs hand-picked" source fields shared by the live-data sections,
     * grouped into one titled fieldset.
     *
     * Auto shows the newest from the catalogue (bounded by a limit); manual lets
     * the client pick exact records, in order. Either way the list is live — no
     * copy is duplicated into the block, so a renamed webinar is renamed here.
     *
     * @param  Closure(): array<int, string>  $options
     */
    private static function source(string $noun, Closure $options): Fieldset
    {
        return Fieldset::make('Which '.$noun.' to show')
            ->columns(2)
            ->schema([
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
            ]);
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
}
