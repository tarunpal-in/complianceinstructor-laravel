<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResolveSocialCustomer;
use App\Http\Controllers\Controller;
use App\Support\SocialProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Social sign-in for customers — §9. Three providers (Google, LinkedIn,
 * Microsoft), each gated on its credentials so a disabled one is a 404 rather
 * than a broken redirect.
 *
 * The callback never trusts the provider for authorisation: ResolveSocialCustomer
 * decides who the account is and refuses staff, so a social button cannot become
 * a second way into an admin session without two-factor.
 */
class SocialAuthController extends Controller
{
    public function redirect(string $provider): SymfonyRedirect
    {
        abort_unless(SocialProviders::isEnabled($provider), 404);

        return Socialite::driver(SocialProviders::driver($provider))->redirect();
    }

    public function callback(string $provider, ResolveSocialCustomer $resolve): RedirectResponse
    {
        abort_unless(SocialProviders::isEnabled($provider), 404);

        try {
            $social = Socialite::driver(SocialProviders::driver($provider))->user();
        } catch (Throwable) {
            return redirect()->route('login')->with(
                'authError',
                'We could not sign you in with '.SocialProviders::label($provider).'. Please try again.',
            );
        }

        try {
            $user = $resolve($social);
        } catch (RuntimeException $exception) {
            return redirect()->route('login')->with('authError', $this->reason($exception, $provider));
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended(route('filament.account.pages.upcoming-webinars'));
    }

    private function reason(RuntimeException $exception, string $provider): string
    {
        return match ($exception->getMessage()) {
            'staff' => 'That email belongs to a staff account. Please sign in through the admin area instead.',
            'did-not-share-email' => SocialProviders::label($provider).' did not share an email address, so we could not sign you in.',
            default => 'We could not sign you in. Please try again.',
        };
    }
}
