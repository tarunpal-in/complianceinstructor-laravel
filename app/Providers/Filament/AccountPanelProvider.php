<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->profile(isSimple: false)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Color::Blue,
                'gray' => Color::Slate,
            ])
            ->pages([
                Dashboard::class,
            ])
            // The learner area (P5) — its own namespace so none of the admin
            // resources or pages are ever discovered into the customer panel.
            ->discoverPages(in: app_path('Filament/Account/Pages'), for: 'App\\Filament\\Account\\Pages')
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
