<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The customer account panel — docs/auth.md's chosen owner of public auth, and
 * the home of the learner area (P5).
 *
 * Why a second Filament panel rather than Fortify or hand-built Blade: it brings
 * login, registration, password reset, email verification and (if ever wanted)
 * two-factor already built, consistent with §2's "Filament owns the panel"
 * leverage — and, decisively, it registers no global `POST /login`. Each panel
 * authenticates through its own login page, so there is no second login that
 * skips the admin's two-factor challenge, which is the bypass docs/auth.md
 * refused Fortify over.
 *
 * Staff and customers are kept strictly apart: `User::canAccessPanel` lets only
 * a non-staff account in here, and only a role-holder into admin. A staff member
 * therefore cannot authenticate through this panel at all, so they cannot reach
 * a `web` session that never faced two-factor.
 *
 * It discovers none of the admin resources, pages or widgets — a customer sees
 * only their own area. The dashboard is intentionally bare until P5 fills it.
 */
class AccountPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('account')
            ->path('account')
            ->brandName('Compliance Instructor')
            // Login and registration are the branded /login page (a Livewire
            // component), not Filament's own — so there is one on-brand customer
            // sign-in. The panel keeps password reset, email verification and the
            // profile page; an unauthenticated visitor is sent to route('login').
            ->passwordReset()
            ->emailVerification()
            ->profile(isSimple: false)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                // Our brand blue drives buttons and the active nav item — the
                // customer area wears our colours, not Filament's default.
                'primary' => Color::hex('#0c5484'),
                'gray' => Color::Slate,
            ])
            // Light only, like the public site. The brand skin is built for the
            // cream/blue palette, and dark mode left the heading and brand text
            // unreadable on a dark background — so there is no dark toggle here.
            ->darkMode(false)
            // No bare "Dashboard": the member lands on Upcoming Webinars and every
            // destination is one click in the sidebar — one menu item per thing,
            // for a non-technical audience (the simplicity-first direction).
            // The learner pages are discovered from their own namespace, so none
            // of the admin resources or pages reach the customer panel.
            ->discoverPages(in: app_path('Filament/Account/Pages'), for: 'App\\Filament\\Account\\Pages')
            // The public site's colours (deep blue, gold, warm cream) carried
            // into the panel's chrome and the learner cards — injected into
            // <head> so it does not depend on Filament's Tailwind build.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render('@include("filament.account.partials.account-theme")'),
            )
            // A clear way back to the public catalogue and site from the panel.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): string => Blade::render('@include("filament.account.partials.site-links")'),
            )
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
