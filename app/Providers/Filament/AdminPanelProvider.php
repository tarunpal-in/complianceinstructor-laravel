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
use Filament\Widgets\AccountWidget;
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
            ->widgets([
                AccountWidget::class,
            ])
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
     * Customers is absent because UserResource already occupies that slot.
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
            ['Catalogue', 10, 'Webinars', Heroicon::OutlinedVideoCamera],
            ['Catalogue', 20, 'Packages', Heroicon::OutlinedCube],
            ['Catalogue', 30, 'Speakers', Heroicon::OutlinedMicrophone],
            ['Catalogue', 40, 'Industries', Heroicon::OutlinedBuildingOffice2],

            ['Sales', 10, 'Orders', Heroicon::OutlinedShoppingBag],
            ['Sales', 20, 'Payments', Heroicon::OutlinedCreditCard],
            ['Sales', 30, 'Coupons', Heroicon::OutlinedTicket],

            ['Memberships', 10, 'Plans', Heroicon::OutlinedRectangleStack],
            ['Memberships', 20, 'Subscriptions', Heroicon::OutlinedArrowPath],

            ['Learners', 20, 'Entitlements', Heroicon::OutlinedKey],
            ['Learners', 30, 'Certificates', Heroicon::OutlinedAcademicCap],

            ['Content', 10, 'Pages', Heroicon::OutlinedDocumentText],
            ['Content', 20, 'Blog', Heroicon::OutlinedNewspaper],

            ['Inbox', 10, 'Leads', Heroicon::OutlinedUserPlus],
            ['Inbox', 20, 'Subscribers', Heroicon::OutlinedEnvelope],
            ['Inbox', 30, 'Inquiries', Heroicon::OutlinedChatBubbleLeftRight],

            ['Reports', 10, 'Reports', Heroicon::OutlinedChartBar],

            ['System', 10, 'Settings', Heroicon::OutlinedCog6Tooth],
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
