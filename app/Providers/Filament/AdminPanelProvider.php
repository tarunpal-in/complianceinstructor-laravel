<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Auth\AppAuthentication;
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
            /*
             * isSimple: false puts the profile inside the panel shell.
             *
             * Filament defaults it to true, which renders a bare centred card
             * with no sidebar and no topbar — sensible for a panel whose
             * profile page is a detour, and wrong here: it is where somebody
             * sets up two-factor, and landing on a page with no way back
             * reads as having been logged out.
             */
            ->profile(isSimple: false)
            /*
             * Two-factor, optional for now (§3 promises it for admins).
             *
             * Filament's own. Fortify was removed on 2026-10-02 partly
             * because of this: its unconditional POST /login would have
             * authenticated on the same session without ever reaching this
             * challenge. See docs/auth.md.
             *
             * Passing isRequired: true to the second argument is the whole of
             * turning enforcement on later. It is off deliberately: with one
             * Super Admin, requiring a second factor before anybody has set
             * one up is a lockout waiting for a lost phone.
             */
            ->multiFactorAuthentication([
                // Ours, not Filament's — theirs renders a broken QR code wherever
                // the imagick extension is absent. See App\Auth\AppAuthentication.
                AppAuthentication::make()
                    ->recoverable()
                    // What the authenticator app calls it in its list.
                    ->brandName('Compliance Instructor')
                    /*
                     * Filament defaults to 8, which accepts a code for four
                     * minutes either side. One window is the usual figure and
                     * still covers ordinary clock drift; four minutes is a
                     * long time for a code somebody read over a shoulder.
                     */
                    ->codeWindow(1),
            ])
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
            ->navigationItems([
                ...$this->placeholderNavigation(),
                // A way back to the public site from the panel (the mirror of the
                // storefront's staff bar). Same tab, so the frontend's own "Admin
                // dashboard" bar brings them straight back.
                NavigationItem::make('View site')
                    ->url(fn (): string => route('home'))
                    ->icon(Heroicon::OutlinedHome)
                    ->sort(-100),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Clusters group related pages and resources under one sidebar item.
            // The Emails cluster folds the overview and the log together; its
            // members stay in their own directories and join it via $cluster.
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
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
     * Entitlements, Certificates, Pages, Blog, Inquiries, Subscribers — and
     * Accreditations, which the resource adds to Catalogue, Sales under
     * Reports, and Staff and Roles under System — the one stub that became two
     * entries, because a screen for who may get in and a screen for what a
     * role may do are different jobs.
     *
     * Learners, Inbox and Reports are entirely real.
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
