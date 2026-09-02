<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Launch readiness, on the dashboard.
 *
 * A dashboard built only of metrics is empty until there is trade to measure —
 * orders arrive in P3, subscriptions in P4. This gives the panel something
 * useful to say from day one, and it maps directly onto CLAUDE.md §12's
 * content-readiness risk, which it names as the most common cause of a slipped
 * launch: the client can see what is still outstanding without being told.
 *
 * It removes itself once every item is done (see canView), so it is scaffolding
 * rather than furniture.
 *
 * Checks against tables that do not exist yet are guarded with Schema::hasTable
 * and simply read as incomplete, so each item starts working on its own as the
 * migration behind it lands.
 */
class SetupChecklist extends Widget
{
    protected string $view = 'filament.widgets.setup-checklist';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -20;

    /**
     * Rendered with the page rather than in a follow-up Livewire request.
     * Filament lazy-loads widgets by default, which is right for the trend
     * charts coming in P8 but wrong for a couple of indexed counts above the
     * fold — it costs a round trip and a layout shift for nothing.
     */
    protected static bool $isLazy = false;

    /**
     * The preference key that hides this card for good, per account.
     */
    public const DISMISSED = 'dashboard.setup_checklist_dismissed';

    /**
     * Super Admin only — `view_any_setting` is held by no other role. Hidden
     * once dismissed, and once nothing is outstanding, so a finished panel is
     * not carrying a row of ticks forever.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user?->can('view_any_setting')) {
            return false;
        }

        if ($user->preference(self::DISMISSED, false)) {
            return false;
        }

        return (new self)->outstanding() > 0;
    }

    /**
     * Hide the card permanently for this account.
     *
     * Stored per user rather than site-wide: one admin skipping setup should not
     * decide it for a colleague. The list itself stays reachable at
     * System > Settings, where it can be switched back on.
     */
    public function dismiss(): void
    {
        auth()->user()?->setPreference(self::DISMISSED, true);

        Notification::make()
            ->title('Setup checklist hidden')
            ->body('You can see it again, and bring it back to the dashboard, from System → Settings.')
            ->success()
            ->send();

        // canView() is evaluated when the dashboard composes its widgets, so a
        // Livewire re-render alone would leave the card on screen.
        $this->redirect(Dashboard::getUrl());
    }

    /**
     * @return array<int, array{label: string, description: string, done: bool, url: string}>
     */
    public function items(): array
    {
        return [
            [
                'label' => 'Roles and permissions configured',
                'description' => 'Super Admin, Content Editor, Finance and Support.',
                'done' => Role::query()->count() >= 4,
                'url' => '#',
            ],
            [
                'label' => 'Secure your account with two-factor authentication',
                'description' => 'Admin accounts hold customer and payment data.',
                // Queried rather than read off the authenticated model: under
                // Model::shouldBeStrict() an attribute the instance never
                // hydrated throws instead of returning null.
                'done' => User::query()
                    ->whereKey(auth()->id())
                    ->whereNotNull('two_factor_confirmed_at')
                    ->exists(),
                'url' => '#',
            ],
            [
                'label' => 'Invite your team',
                'description' => 'Give colleagues the role that matches their job.',
                'done' => User::query()->has('roles')->count() > 1,
                'url' => '#',
            ],
            [
                'label' => 'Set your brand and contact details',
                'description' => 'Logo, favicon, address and support email.',
                'done' => $this->tableHasRows('settings'),
                'url' => '#',
            ],
            [
                'label' => 'Add your speakers',
                'description' => 'Their credentials are what customers buy.',
                'done' => $this->tableHasRows('speakers'),
                'url' => '#',
            ],
            [
                'label' => 'Add your first webinar',
                'description' => 'With its accreditation credits and offerings.',
                'done' => $this->tableHasRows('webinars'),
                'url' => '#',
            ],
            [
                'label' => 'Connect Stripe',
                'description' => 'Cards, wallets and all subscription billing.',
                'done' => filled(config('cashier.secret')),
                'url' => '#',
            ],
            [
                'label' => 'Connect PayPal',
                'description' => 'The second gateway for one-off orders.',
                'done' => filled(config('services.paypal.client_secret')),
                'url' => '#',
            ],
            [
                'label' => 'Set up transactional email',
                'description' => 'Receipts and reminders cannot go to the log file.',
                'done' => ! in_array(config('mail.default'), ['log', 'array', null], true),
                'url' => '#',
            ],
        ];
    }

    public function completed(): int
    {
        return count(array_filter($this->items(), static fn (array $item): bool => $item['done']));
    }

    public function total(): int
    {
        return count($this->items());
    }

    public function outstanding(): int
    {
        return $this->total() - $this->completed();
    }

    public function percentage(): int
    {
        return (int) round($this->completed() / max($this->total(), 1) * 100);
    }

    /**
     * False when the table has not been migrated yet, so an item for a phase
     * that has not happened reads as outstanding rather than erroring.
     */
    private function tableHasRows(string $table): bool
    {
        return Schema::hasTable($table)
            && DB::table($table)->exists();
    }
}
