<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\SetupChecklist;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Site settings.
 *
 * Currently only the setup checklist, which needed somewhere to live that is
 * not the dashboard: skipping it there would otherwise be a one-way door with
 * no way to see the list again.
 *
 * CLAUDE.md §5.6's `settings` table — brand, contact, social, promo bar, trust
 * figures, SEO defaults — lands here as tabs alongside it.
 */
class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|null|UnitEnum $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_any_setting') ?? false;
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
