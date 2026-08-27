<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
            // The navigation skeleton from §6's fourteen modules. Groups exist
            // before the resources do so that each resource added in P2+ has an
            // obvious home rather than accumulating a flat list of 40 items.
            ->navigationGroups([
                NavigationGroup::make('Catalogue'),
                NavigationGroup::make('Commerce'),
                NavigationGroup::make('Memberships'),
                NavigationGroup::make('Learners'),
                NavigationGroup::make('Content'),
                NavigationGroup::make('Engagement'),
                NavigationGroup::make('System'),
            ])
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
}
