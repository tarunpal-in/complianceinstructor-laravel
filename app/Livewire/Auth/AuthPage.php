<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Auth\Concerns\AuthenticatesCustomers;
use App\Support\SocialProviders;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The customer sign-in / sign-up page — one branded page for both (P5 UX).
 *
 * Replaces the Filament account panel's own login and registration. The sign-in
 * and sign-up behaviour lives in AuthenticatesCustomers, shared with the inline
 * form on the cart; this page adds the URL-bound mode and the branded layout.
 */
class AuthPage extends Component
{
    use AuthenticatesCustomers;

    #[Url]
    public string $mode = 'login';

    public function mount(): void
    {
        $this->mode = $this->mode === 'register' ? 'register' : 'login';

        if (Auth::check()) {
            $this->redirectRoute('filament.account.pages.upcoming-webinars', navigate: false);
        }
    }

    public function render(): View
    {
        return view('livewire.auth.auth-page', [
            'providers' => SocialProviders::enabled(),
        ])->layout('layouts.auth', ['title' => $this->mode === 'register' ? 'Create your account' : 'Sign in']);
    }

    protected function afterAuthRedirect(): mixed
    {
        return $this->redirect(
            session()->pull('url.intended', route('filament.account.pages.upcoming-webinars')),
            navigate: false,
        );
    }
}
