<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The admin panel. CLAUDE.md §2 makes this the entire admin surface — every
 * admin screen is a Filament resource, never a hand-written controller plus
 * Blade form. That decision is worth ~10 weeks and is load-bearing.
 *
 * Note §9: Filament applies middleware at PANEL level, so no route can be
 * accidentally left unprotected the way the predecessor's bulk-import
 * endpoint was.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('Compliance Instructor')
            ->login()
            ->passwordReset()
            ->profile()
            ->databaseNotifications()
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            // Local SVG initials instead of Filament's default, which
            // requests every admin's name from ui-avatars.com.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Puts light/dark next to the search field rather than two
            // clicks deep inside the user-menu dropdown.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): View => view('filament.topbar.theme-switcher'),
            )
            // Provisional palette. CLAUDE.md §7 P0 locks brand and design
            // direction with the client; revisit once that lands.
            ->colors([
                'primary' => Color::Blue,
                'gray' => Color::Slate,
            ])
            // Eight groups, agreed 2026-09-02. Order here is the sidebar order.
            // Forum and Quizzes are absent deliberately — both were cut (§13
            // ladder items #1 and #4), so neither gets an admin surface.
            ->navigationGroups([
                NavigationGroup::make('Catalogue'),
                NavigationGroup::make('Sales'),
                NavigationGroup::make('Memberships'),
                NavigationGroup::make('Learners'),
                NavigationGroup::make('Content'),
                NavigationGroup::make('Inbox'),
                NavigationGroup::make('Reports'),
                NavigationGroup::make('System'),
            ])
            ->navigationItems($this->placeholderNavigation())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // No AccountWidget: it repeats the user menu already in the topbar
            // and takes the best position on the page. Widgets are discovered
            // from app/Filament/Widgets instead.
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * The agreed sidebar, stubbed.
     *
     * Every item points at '#'. They exist so the shape of the panel is settled
     * and reviewable now, before the resources behind them are built: as each
     * one lands in P2–P8, its stub here is deleted and the resource takes the
     * same group and sort position, so the sidebar never reshuffles under the
     * client.
     *
     * Absent because a real resource or page already occupies the slot:
     * Customers, Settings, Speakers, Industries, Plans, Subscriptions,
     * Entitlements, Certificates, Pages, Blog, Inquiries — and Accreditations,
     * which the resource adds to Catalogue. The whole Learners group is now
     * real, and Inbox is down to Subscribers.
     *
     * Leads is gone rather than built: the register that would have sat there
     * is the same screen as Inquiries, and the client's intake is the website
     * contact form. One Inbox screen, with `source` recording how somebody
     * arrived — see the rename migration.
     *
     * A stub left behind after its resource lands shows as two identical items
     * in the same group, one of which goes nowhere. AdminDashboardTest asserts
     * no label appears in both lists, because "does the sidebar contain
     * Inquiries" is true either way and will not catch it.
     *
     * Not yet permission-gated — real resources gate on `view_any_*` through
     * their own can* methods (§8). Stubs are visible to any admin role until
     * they are replaced.
     *
     * @return array<int, NavigationItem>
     */
    private function placeholderNavigation(): array
    {
        /** @var array<int, array{0: string, 1: int, 2: string, 3: Heroicon}> $items */
        $items = [
            ['Catalogue', 20, 'Packages', Heroicon::OutlinedCube],

            ['Inbox', 20, 'Subscribers', Heroicon::OutlinedEnvelope],

            ['Reports', 10, 'Reports', Heroicon::OutlinedChartBar],

            ['System', 20, 'Staff & Roles', Heroicon::OutlinedShieldCheck],
            ['System', 30, 'Activity Log', Heroicon::OutlinedClipboardDocumentList],
            ['System', 40, 'Backups', Heroicon::OutlinedArchiveBox],
        ];

        return array_map(
            static fn (array $item): NavigationItem => NavigationItem::make($item[2])
                ->group($item[0])
                ->sort($item[1])
                ->icon($item[3])
                ->url('#'),
            $items,
        );
    }
}
