<?php

declare(strict_types=1);

namespace App\Livewire\Auth\Concerns;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * The customer sign-in / sign-up behaviour shared by the standalone auth page
 * and the inline form on the cart (P5 UX / "no checkout without an account").
 *
 * Both reach a *customer* account only: staff are refused and sent to /admin, so
 * neither entry point can become a login that skipped the admin two-factor
 * challenge (docs/auth.md). The only thing that differs between the two is where
 * a successful sign-in lands, which each component supplies via afterAuthRedirect().
 *
 * `$mode` is declared by the using component (the standalone page binds it to the
 * URL; the cart form does not), so it is not declared here.
 */
trait AuthenticatesCustomers
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    // Registration only.
    public string $name = '';

    public string $password_confirmation = '';

    public function switchMode(string $mode): void
    {
        $this->mode = $mode === 'register' ? 'register' : 'login';
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function login(): mixed
    {
        $data = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->guardAgainstThrottle();

        $user = User::query()->where('email', $data['email'])->first();

        // Staff authenticate only at /admin, which enforces two-factor.
        if ($user?->isStaff()) {
            throw ValidationException::withMessages([
                'email' => 'That is a staff account. Please sign in through the admin area.',
            ]);
        }

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Those details did not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        return $this->afterAuthRedirect();
    }

    public function register(): mixed
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        // Sign-up stays short on purpose: just the account. The buyer's company,
        // phone and billing address are captured at checkout (BillingDetails) and
        // frozen onto the order, so nothing extra is asked of them to register.
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->save();

        // Fires our verification mail (AppServiceProvider carries it through the
        // Dispatcher); the account panel then requires the address be confirmed.
        event(new Registered($user));

        Auth::login($user);
        session()->regenerate();

        return $this->afterAuthRedirect();
    }

    /** Where a successful sign-in or registration lands. */
    abstract protected function afterAuthRedirect(): mixed;

    /**
     * §9 — rate limiting on login. Keyed by email and IP so one account or one
     * machine hammering is stopped without locking out everyone behind a shared
     * address.
     */
    private function guardAgainstThrottle(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($this->throttleKey()).' seconds.',
            ]);
        }
    }

    private function throttleKey(): string
    {
        return 'login:'.Str::lower($this->email).'|'.request()->ip();
    }
}
