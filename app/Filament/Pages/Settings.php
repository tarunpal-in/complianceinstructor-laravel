<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\SetupChecklist;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Site settings (CLAUDE.md §5.6).
 *
 * The promo bar is the first group. Brand, contact, social, trust figures and
 * SEO defaults join it as further sections against the same `settings` table.
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
            'promo_bar_enabled' => (bool) Setting::get('promo_bar.enabled', true),
            'promo_bar_text' => Setting::get('promo_bar.text'),
            'promo_bar_code' => Setting::get('promo_bar.code'),
            'promo_bar_background_color' => Setting::color('promo_bar.background_color', '#0c4d80'),
            'promo_bar_text_color' => Setting::color('promo_bar.text_color', '#ffffff'),
            'promo_bar_font_size' => Setting::pixels('promo_bar.font_size', 14),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Promo bar')
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
                            ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled')),

                        TextInput::make('promo_bar_code')
                            ->label('Coupon code')
                            ->maxLength(40)
                            ->helperText('Displayed only. Coupons are still validated server-side at checkout.')
                            ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled')),

                        ColorPicker::make('promo_bar_background_color')
                            ->label('Background colour')
                            ->required()
                            ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled')),

                        ColorPicker::make('promo_bar_text_color')
                            ->label('Text colour')
                            ->required()
                            ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled')),

                        TextInput::make('promo_bar_font_size')
                            ->label('Font size')
                            ->numeric()
                            ->required()
                            // Bounded because the bar is a fixed 52px tall; past
                            // this the text clips or the strip looks broken.
                            ->minValue(10)
                            ->maxValue(20)
                            ->suffix('px')
                            ->visible(fn (callable $get): bool => (bool) $get('promo_bar_enabled')),
                    ])
                    ->columns(2),
            ]);
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

    public function save(): void
    {
        $data = $this->getForm('form')?->getState() ?? [];

        Setting::set('promo_bar.enabled', (bool) $data['promo_bar_enabled'], 'boolean');
        Setting::set('promo_bar.text', (string) $data['promo_bar_text'], 'string');
        Setting::set('promo_bar.code', (string) ($data['promo_bar_code'] ?? ''), 'string');
        Setting::set('promo_bar.background_color', (string) $data['promo_bar_background_color'], 'color');
        Setting::set('promo_bar.text_color', (string) $data['promo_bar_text_color'], 'color');
        Setting::set('promo_bar.font_size', (int) $data['promo_bar_font_size'], 'integer');

        Notification::make()->title('Settings saved')->success()->send();
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
