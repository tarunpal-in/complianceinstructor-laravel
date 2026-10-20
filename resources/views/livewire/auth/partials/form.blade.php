{{-- The two-tab customer sign-in / sign-up form.
     Expects: $mode, $providers. Optional: $heading, $sub. --}}
<div class="auth-toggle" role="tablist">
    <button type="button" role="tab" @class(['on' => $mode === 'login']) wire:click="switchMode('login')">Sign in</button>
    <button type="button" role="tab" @class(['on' => $mode === 'register']) wire:click="switchMode('register')">Create account</button>
</div>

<h1 class="auth-title">{{ $heading ?? ($mode === 'register' ? 'Create your account' : 'Welcome back') }}</h1>
<p class="auth-sub">{{ $sub ?? ($mode === 'register' ? 'Join to buy webinars, track credits and download certificates.' : 'Sign in to reach your webinars, invoices and certificates.') }}</p>

@if (session('authError'))
    <p class="auth-flash auth-flash-error" role="alert">{{ session('authError') }}</p>
@endif

{{-- Social sign-in (only the configured providers) --}}
@if (! empty($providers))
    <div class="auth-social">
        @foreach ($providers as $slug => $label)
            <a class="auth-social-btn auth-social-{{ $slug }}" href="{{ route('auth.redirect', $slug) }}">
                <span class="auth-social-glyph">{{ substr($label, 0, 1) }}</span>
                Continue with {{ $label }}
            </a>
        @endforeach
    </div>
    <div class="auth-divider"><span>or with email</span></div>
@endif

@if ($mode === 'login')
    <form wire:submit="login" class="auth-form">
        <label class="auth-field">
            <span>Email</span>
            <input type="email" wire:model="email" autocomplete="email">
            @error('email') <em>{{ $message }}</em> @enderror
        </label>
        <label class="auth-field">
            <span>Password</span>
            <input type="password" wire:model="password" autocomplete="current-password">
            @error('password') <em>{{ $message }}</em> @enderror
        </label>
        <div class="auth-row">
            <label class="auth-remember">
                <input type="checkbox" wire:model="remember"> Remember me
            </label>
            <a class="auth-link" href="{{ route('filament.account.auth.password-reset.request') }}">Forgot password?</a>
        </div>
        <button type="submit" class="auth-submit" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
@else
    <form wire:submit="register" class="auth-form">
        <label class="auth-field">
            <span>Full name</span>
            <input type="text" wire:model="name" autocomplete="name">
            @error('name') <em>{{ $message }}</em> @enderror
        </label>
        <label class="auth-field">
            <span>Email</span>
            <input type="email" wire:model="email" autocomplete="email">
            @error('email') <em>{{ $message }}</em> @enderror
        </label>
        <label class="auth-field">
            <span>Password</span>
            <input type="password" wire:model="password" autocomplete="new-password">
            @error('password') <em>{{ $message }}</em> @enderror
        </label>
        <label class="auth-field">
            <span>Confirm password</span>
            <input type="password" wire:model="password_confirmation" autocomplete="new-password">
        </label>
        <button type="submit" class="auth-submit" wire:loading.attr="disabled" wire:target="register">
            <span wire:loading.remove wire:target="register">Create account</span>
            <span wire:loading wire:target="register">Creating…</span>
        </button>
    </form>
@endif

<p class="auth-switch">
    @if ($mode === 'login')
        New here? <button type="button" wire:click="switchMode('register')">Create an account</button>
    @else
        Already have an account? <button type="button" wire:click="switchMode('login')">Sign in</button>
    @endif
</p>
