<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Orders\GenerateOrderNumber;
use App\Enums\OrderNumberReset;
use App\Filament\Forms\Components\MediaPicker;
use App\Filament\Widgets\SetupChecklist;
use App\Models\Setting;
use App\Settings\SettingDefinition;
use App\Settings\SettingField;
use App\Settings\SettingGroup;
use App\Settings\SettingRegistry;
use App\Support\OrderNumberFormat;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Site settings (CLAUDE.md §5.6).
 *
 * Six tabs, one per SettingGroup, and each tab is editable only by a role that
 * owns it — marketing copy and the refund cooling-off window were previously
 * one screen behind one permission, so granting the first granted the second.
 *
 * The plain groups draw themselves from SettingRegistry. Brand and Commerce do
 * not: media pickers record what uses them, and order numbering validates three
 * fields against each other and previews the result. Metadata draws what is
 * boring; anything interesting is written out where it can be read.
 *
 * Also hosts the setup checklist, which needed somewhere to live that is not the
 * dashboard — skipping it there would otherwise be a one-way door.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|null|UnitEnum $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_any_setting') ?? false;
    }

    public function mount(): void
    {
        // getForm() rather than $this->form: the property is magic on
        // InteractsWithForms and invisible to static analysis.
        $this->getForm('form')?->fill([
            'brand_logo_media_id' => Setting::mediaId('brand.logo_media_id'),
            'brand_logo_dark_media_id' => Setting::mediaId('brand.logo_dark_media_id'),
            'brand_favicon_media_id' => Setting::mediaId('brand.favicon_media_id'),
            'brand_og_default_media_id' => Setting::mediaId('brand.og_default_media_id'),

            'order_number_format' => GenerateOrderNumber::format()->pattern,
            'order_number_padding' => GenerateOrderNumber::format()->padding,
            'order_number_reset' => GenerateOrderNumber::format()->reset->value,
            'order_year_start_month' => GenerateOrderNumber::format()->yearStartMonth,
            'order_number_next' => GenerateOrderNumber::peek(),

            'promo_bar_enabled' => (bool) Setting::declared('promo_bar.enabled'),
            'promo_bar_text' => Setting::declared('promo_bar.text'),
            'promo_bar_code' => Setting::declared('promo_bar.code'),
            'promo_bar_background_color' => Setting::color('promo_bar.background_color', '#0c4d80'),
            'promo_bar_text_color' => Setting::color('promo_bar.text_color', '#ffffff'),
            'promo_bar_font_size' => Setting::pixels('promo_bar.font_size', 14),

            // Everything the registry draws, filled from its own declared
            // defaults so a fresh install shows the values actually in force
            // rather than a screen of blanks.
            ...self::generatedState(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('settings')->columnSpanFull()->tabs([
                    Tabs\Tab::make(SettingGroup::Business->label())->schema(self::generated(SettingGroup::Business)),

                    Tabs\Tab::make(SettingGroup::Brand->label())->schema($this->brandTab()),

                    Tabs\Tab::make(SettingGroup::Commerce->label())->schema($this->commerceTab()),

                    Tabs\Tab::make(SettingGroup::Catalogue->label())->schema(self::generated(SettingGroup::Catalogue)),

                    Tabs\Tab::make(SettingGroup::Certificates->label())->schema(self::generated(SettingGroup::Certificates)),

                    Tabs\Tab::make(SettingGroup::Email->label())->schema(self::generated(SettingGroup::Email)),

                    Tabs\Tab::make(SettingGroup::Integrations->label())->schema(self::generated(SettingGroup::Integrations)),
                ]),
            ]);
    }

    /**
     * The plain groups, drawn from the registry.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private static function generated(SettingGroup $group): array
    {
        $editable = $group->isEditableBy(auth()->user());

        return [
            Section::make($group->label())
                ->description($group->description().($editable ? '' : ' You do not have permission to change these.'))
                ->columns(2)
                ->schema(array_map(
                    fn (SettingDefinition $setting) => SettingField::for($setting, $editable),
                    SettingRegistry::forGroup($group),
                )),
        ];
    }

    /**
     * Current values for every generated field.
     *
     * @return array<string, mixed>
     */
    private static function generatedState(): array
    {
        $state = [];

        foreach (SettingRegistry::all() as $setting) {
            // Brand and Commerce draw their own tabs by hand; Payments lives on
            // its own page (PaymentGateways) and has no tab here at all.
            if (in_array($setting->group, [SettingGroup::Brand, SettingGroup::Commerce, SettingGroup::Payments], true)) {
                continue;
            }

            $state[$setting->field()] = $setting->value();
        }

        return $state;
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function brandTab(): array
    {
        return [
            Section::make('Brand')
                ->description('Picked from the media library, so replacing a file there updates it everywhere at once.')
                ->schema([
                    MediaPicker::make('brand_logo_media_id')
                        ->label('Logo')
                        ->helperText('Shown in the header and on invoices.'),

                    MediaPicker::make('brand_logo_dark_media_id')
                        ->label('Logo (dark backgrounds)')
                        ->helperText('Optional. Used in the footer and anywhere the background is dark.'),

                    MediaPicker::make('brand_favicon_media_id')
                        ->label('Favicon')
                        ->helperText('A square PNG or SVG. Browsers cache these hard, so a change can take a while to show.'),

                    MediaPicker::make('brand_og_default_media_id')
                        ->label('Default social image')
                        ->helperText('Used when a page has no image of its own. 1200×630 works everywhere.'),
                ])
                ->columns(2),

            $this->promoBarSection(),
        ];
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function commerceTab(): array
    {
        $editable = SettingGroup::Commerce->isEditableBy(auth()->user());

        return [
            Section::make('Order numbers')
                ->description('The reference on every invoice. Existing orders keep the number they were given.')
                ->columns(2)
                ->schema([
                    TextInput::make('order_number_format')
                        ->label('Format')
                        ->required()
                        ->maxLength(64)
                        ->live(onBlur: true)
                        ->helperText(new HtmlString(
                            'Tokens: <code>{YYYY}</code> <code>{YY}</code> <code>{FY}</code> '
                            .'<code>{MM}</code> <code>{DD}</code> <code>{SEQ}</code>. Anything else '
                            .'is literal, so <code>CI-{YYYY}-{SEQ}</code> keeps the prefix. '
                            .'<code>{FY}</code> writes the span — <code>2024-25</code>.'
                        ))
                        // Server-side, and checked against the reset period
                        // rather than in isolation: the two only collide in
                        // combination. See OrderNumberFormat::problems().
                        ->rule(static fn (callable $get): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($get): void {
                            foreach (self::formatFrom($get)->problems() as $problem) {
                                $fail($problem);
                            }
                        }),

                    Select::make('order_number_reset')
                        ->label('Restart the sequence')
                        ->options(OrderNumberReset::options())
                        ->required()
                        ->live()
                        ->helperText('The format has to carry whatever the sequence restarts on, or two periods would produce the same number.'),

                    Select::make('order_year_start_month')
                        ->label('Accounting year starts in')
                        ->options(self::months())
                        ->required()
                        ->live()
                        // The reason the year tokens are not simply the
                        // calendar year: with an April start the sequence
                        // restarts in April, so January–March must still
                        // carry the year that began the previous April.
                        ->helperText('April gives a 1 April – 31 March year. January is the calendar year.'),

                    TextInput::make('order_number_padding')
                        ->label('Sequence digits')
                        ->numeric()
                        ->required()
                        ->minValue(OrderNumberFormat::MIN_PADDING)
                        ->maxValue(OrderNumberFormat::MAX_PADDING)
                        ->live(onBlur: true)
                        ->helperText('3 gives 001, 4 gives 0001.'),

                    TextInput::make('order_number_next')
                        ->label('Next number in this period')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->live(onBlur: true)
                        // The reason this is editable at all: the live site
                        // already has order numbers, and the import needs to
                        // continue past them rather than collide.
                        ->helperText('Set this to carry on from the numbers already used on the old site.'),

                    Placeholder::make('order_number_preview')
                        ->label('The next three orders would be')
                        ->columnSpanFull()
                        ->content(fn (callable $get): HtmlString => self::previewFrom($get)),
                ])
                ->disabled(! $editable),

            /*
                 * The setting that had been live and unreachable. An agreed
                 * refund waits this long before the money actually leaves,
                 * which is the window in which a mistake can still be caught.
                 */
            Section::make('Refunds')
                ->description('The safety window between agreeing a refund and sending it.')
                ->columns(2)
                ->schema(array_map(
                    fn (SettingDefinition $setting) => SettingField::for($setting, $editable),
                    array_values(array_filter(
                        SettingRegistry::forGroup(SettingGroup::Commerce),
                        fn (SettingDefinition $setting): bool => str_starts_with($setting->key, 'orders.refund'),
                    )),
                )),
        ];
    }

    private function promoBarSection(): Section
    {
        return Section::make('Promo bar')
            ->description('The strip above the header on every public page.')
            ->schema([
                Toggle::make('promo_bar_enabled')
                    ->label('Show the promo bar')
                    ->helperText('Turning this off removes the strip entirely.')
                    ->live(),

                TextInput::make('promo_bar_text')
                    ->label('Message')
                    ->required()
                    ->maxLength(160)
                    ->helperText('Use :code where the coupon code should appear. It renders in bold.')
                    ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled'))
                    ->dehydratedWhenHidden(),

                TextInput::make('promo_bar_code')
                    ->label('Coupon code')
                    ->maxLength(40)
                    ->helperText('Displayed only. Coupons are still validated server-side at checkout.')
                    ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled'))
                    ->dehydratedWhenHidden(),

                ColorPicker::make('promo_bar_background_color')
                    ->label('Background colour')
                    ->required()
                    ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled'))
                    ->dehydratedWhenHidden(),

                ColorPicker::make('promo_bar_text_color')
                    ->label('Text colour')
                    ->required()
                    ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled'))
                    ->dehydratedWhenHidden(),

                TextInput::make('promo_bar_font_size')
                    ->label('Font size')
                    ->numeric()
                    ->required()
                    // Bounded because the bar is a fixed 52px tall; past
                    // this the text clips or the strip looks broken.
                    ->minValue(10)
                    ->maxValue(20)
                    ->suffix('px')
                    ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled'))
                    ->dehydratedWhenHidden(),
            ])
            ->columns(2)
            ->disabled(! SettingGroup::Brand->isEditableBy(auth()->user()));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->action('save'),
        ];
    }

    /**
     * Save the groups this person is allowed to save.
     *
     * Group by group rather than field by field. Filament already drops a
     * disabled field from the submitted state, but relying on that alone would
     * make the permission a property of how the form was rendered — and a form
     * can be rendered once and posted twice. The check here is the one that
     * decides.
     */
    public function save(): void
    {
        $data = $this->getForm('form')?->getState() ?? [];
        $user = auth()->user();

        if (SettingGroup::Brand->isEditableBy($user)) {
            $this->saveBrand($data);
        }

        if (SettingGroup::Commerce->isEditableBy($user)) {
            $this->saveCommerce($data);
        }

        // Everything the registry draws, for the groups it owns.
        foreach ([SettingGroup::Business, SettingGroup::Catalogue, SettingGroup::Certificates, SettingGroup::Email, SettingGroup::Integrations] as $group) {
            if (! $group->isEditableBy($user)) {
                continue;
            }

            foreach (SettingRegistry::forGroup($group) as $setting) {
                if (! array_key_exists($setting->field(), $data)) {
                    continue;
                }

                $setting->store(self::cast($setting, $data[$setting->field()]));
            }
        }

        Notification::make()->title('Settings saved')->success()->send();
    }

    /** @param  array<string, mixed>  $data */
    private function saveBrand(array $data): void
    {
        // Fields hidden behind the enabled toggle keep their state via
        // dehydratedWhenHidden(), but a save must not depend on that: an
        // absent key falls back to what is already stored.
        $value = static fn (string $key, string $setting, mixed $fallback): mixed => $data[$key] ?? Setting::get($setting, $fallback);

        // Written before the promo bar so that saving with no brand images
        // selected still clears any stale usage rows for them.
        foreach ([
            'brand_logo_media_id' => 'brand.logo_media_id',
            'brand_logo_dark_media_id' => 'brand.logo_dark_media_id',
            'brand_favicon_media_id' => 'brand.favicon_media_id',
            'brand_og_default_media_id' => 'brand.og_default_media_id',
        ] as $field => $setting) {
            $mediaId = $data[$field] ?? null;

            Setting::set($setting, filled($mediaId) ? (int) $mediaId : null, 'media_id');
        }

        Setting::set('promo_bar.enabled', (bool) ($data['promo_bar_enabled'] ?? false), 'boolean');
        Setting::set('promo_bar.text', (string) $value('promo_bar_text', 'promo_bar.text', ''), 'string');
        Setting::set('promo_bar.code', (string) $value('promo_bar_code', 'promo_bar.code', ''), 'string');
        Setting::set('promo_bar.background_color', (string) $value('promo_bar_background_color', 'promo_bar.background_color', '#0c4d80'), 'color');
        Setting::set('promo_bar.text_color', (string) $value('promo_bar_text_color', 'promo_bar.text_color', '#ffffff'), 'color');
        Setting::set('promo_bar.font_size', (int) $value('promo_bar_font_size', 'promo_bar.font_size', 14), 'integer');
    }

    /** @param  array<string, mixed>  $data */
    private function saveCommerce(array $data): void
    {
        // Written before the counter is touched: setNext() reads the reset
        // period back out of settings to work out which counter row it means,
        // so the new period has to be in place first.
        Setting::set('orders.number_format', (string) $data['order_number_format'], 'string');
        Setting::set('orders.number_padding', (int) $data['order_number_padding'], 'integer');
        Setting::set('orders.number_reset', (string) $data['order_number_reset'], 'string');
        Setting::set('orders.year_start_month', (int) $data['order_year_start_month'], 'integer');

        GenerateOrderNumber::setNext((int) $data['order_number_next']);

        if (array_key_exists('orders_refund_delay_minutes', $data)) {
            Setting::set('orders.refund_delay_minutes', (int) $data['orders_refund_delay_minutes'], 'integer');
        }
    }

    /**
     * A form value as its declared type.
     *
     * Filament hands back strings for numbers and booleans for toggles, and the
     * `settings` value column is JSON — so "24" and 24 would both store, and
     * only one of them reads back as a number.
     */
    private static function cast(SettingDefinition $setting, mixed $value): mixed
    {
        return match ($setting->type) {
            'integer' => (int) $value,
            'boolean' => (bool) $value,
            // Empty means "not set", not an empty string: the registry's
            // default should take over rather than a blank overriding it.
            default => is_string($value) && trim($value) === '' ? null : $value,
        };
    }

    /** @return array<int, string> */
    private static function months(): array
    {
        $months = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = Carbon::create(null, $month, 1)?->format('F') ?? (string) $month;
        }

        return $months;
    }

    /** The format as the form currently stands, not as it is stored. */
    private static function formatFrom(callable $get): OrderNumberFormat
    {
        return new OrderNumberFormat(
            (string) $get('order_number_format'),
            (int) $get('order_number_padding'),
            OrderNumberReset::tryFrom((string) $get('order_number_reset')) ?? OrderNumberReset::Yearly,
            (int) $get('order_year_start_month'),
        );
    }

    /**
     * Three numbers rather than one.
     *
     * Seeing the sequence advance is what makes a wrong restart period obvious
     * — "2024-10-05-001, -002, -003" reads correctly, and so does the mistake.
     */
    private static function previewFrom(callable $get): HtmlString
    {
        $format = self::formatFrom($get);
        $problems = $format->problems();

        if ($problems !== []) {
            return new HtmlString(
                '<span class="text-danger-600 dark:text-danger-400">'.e($problems[0]).'</span>'
            );
        }

        $numbers = $format->preview(max(1, (int) $get('order_number_next')));

        return new HtmlString(
            '<code class="font-medium">'.implode('</code>, <code class="font-medium">', array_map('e', $numbers)).'</code>'
        );
    }

    /**
     * @return array<int, array{label: string, description: string, done: bool, url: string}>
     */
    public function checklistItems(): array
    {
        return (new SetupChecklist)->items();
    }

    public function checklistCompleted(): int
    {
        return (new SetupChecklist)->completed();
    }

    public function checklistTotal(): int
    {
        return (new SetupChecklist)->total();
    }

    /** The checklist rides on this page, so it needs the same gate as the widget. */
    public function canSeeChecklist(): bool
    {
        return auth()->user()?->can('view_setup_checklist') ?? false;
    }

    public function checklistIsHidden(): bool
    {
        return (bool) auth()->user()?->preference(SetupChecklist::DISMISSED, false);
    }

    public function toggleChecklist(): void
    {
        $hidden = $this->checklistIsHidden();

        auth()->user()?->setPreference(SetupChecklist::DISMISSED, ! $hidden);

        Notification::make()
            ->title($hidden ? 'Setup checklist restored' : 'Setup checklist hidden')
            ->body($hidden
                ? 'It will show on the dashboard again until every item is done.'
                : 'It will no longer show on the dashboard.')
            ->success()
            ->send();
    }
}
