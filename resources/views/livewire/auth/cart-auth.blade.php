<div class="auth-card auth-card-inline cart-auth">
    @include('livewire.auth.partials.form', [
        'mode' => $mode,
        'providers' => $providers,
        'heading' => $mode === 'register' ? 'Create your account' : 'Sign in to check out',
        'sub' => 'An account is required to complete your purchase — your cart is saved.',
    ])
</div>
