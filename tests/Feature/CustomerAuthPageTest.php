<?php

declare(strict_types=1);

use App\Actions\Auth\ResolveSocialCustomer;
use App\Enums\UserRole;
use App\Livewire\Auth\AuthPage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;

/*
 * The branded customer sign-in / sign-up page — P5 UX. Social OAuth round-trips
 * need provider credentials and are verified there; the page, the guard, account
 * creation and the staff exclusion are covered here.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

const PW = 'Str0ng-Passw0rd!';

// The page ---------------------------------------------------------------------

it('renders the branded login page', function (): void {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('Welcome back')
        ->assertSee('Create account');
});

it('sends an unauthenticated visitor from the account area to the branded login', function (): void {
    $this->get('/account')->assertRedirect(route('login'));
});

// Email + password -------------------------------------------------------------

it('registers a new customer and signs them in', function (): void {
    // Sign-up stays short: just the account. Company, phone and billing are
    // captured at checkout, not here.
    Livewire::test(AuthPage::class)
        ->set('mode', 'register')
        ->set('name', 'Dana Rivera')
        ->set('email', 'dana@example.test')
        ->set('password', PW)
        ->set('password_confirmation', PW)
        ->call('register')
        ->assertRedirect(route('filament.account.pages.upcoming-webinars'));

    $user = User::query()->where('email', 'dana@example.test')->sole();

    expect($user->isStaff())->toBeFalse()
        ->and(auth()->id())->toBe($user->getKey());
});

it('logs in an existing customer', function (): void {
    $user = User::factory()->create(['email' => 'cust@example.test', 'password' => PW]);

    Livewire::test(AuthPage::class)
        ->set('email', 'cust@example.test')
        ->set('password', PW)
        ->call('login')
        ->assertRedirect(route('filament.account.pages.upcoming-webinars'));

    expect(auth()->id())->toBe($user->getKey());
});

it('rejects wrong credentials', function (): void {
    User::factory()->create(['email' => 'cust@example.test', 'password' => PW]);

    Livewire::test(AuthPage::class)
        ->set('email', 'cust@example.test')
        ->set('password', 'not-the-password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('refuses a staff account at the customer login', function (): void {
    $staff = User::factory()->create(['email' => 'boss@example.test', 'password' => PW]);
    $staff->assignRole(UserRole::SuperAdmin->value);

    Livewire::test(AuthPage::class)
        ->set('email', 'boss@example.test')
        ->set('password', PW)
        ->call('login')
        ->assertHasErrors('email');

    // Never signed in on the web guard — no bypass of the admin two-factor login.
    expect(auth()->check())->toBeFalse();
});

// Social -----------------------------------------------------------------------

it('404s a social provider that is not configured', function (): void {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get(route('auth.redirect', 'google'))->assertNotFound();
});

it('creates and signs in a customer from a social callback', function (): void {
    config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    fakeSocialUser('new.social@example.test', 'Social Newcomer');

    $this->get(route('auth.callback', 'google'))
        ->assertRedirect(route('filament.account.pages.upcoming-webinars'));

    $user = User::query()->where('email', 'new.social@example.test')->sole();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->isStaff())->toBeFalse()
        ->and(auth()->id())->toBe($user->getKey());
});

it('refuses a social sign-in for a staff email', function (): void {
    config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    $staff = User::factory()->create(['email' => 'boss@example.test']);
    $staff->assignRole(UserRole::Finance->value);
    fakeSocialUser('boss@example.test', 'The Boss');

    $this->get(route('auth.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('authError');

    expect(auth()->check())->toBeFalse();
});

it('refuses to resolve a social identity with no email', function (): void {
    $social = Mockery::mock(Laravel\Socialite\Contracts\User::class);
    $social->shouldReceive('getEmail')->andReturn(null);

    expect(fn () => app(ResolveSocialCustomer::class)($social))
        ->toThrow(RuntimeException::class, 'did-not-share-email');
});

/** Point Socialite at a fake provider user for the google driver. */
function fakeSocialUser(string $email, string $name): void
{
    $social = Mockery::mock(Laravel\Socialite\Contracts\User::class);
    $social->shouldReceive('getEmail')->andReturn($email);
    $social->shouldReceive('getName')->andReturn($name);
    $social->shouldReceive('getNickname')->andReturn(null);

    $provider = Mockery::mock(Laravel\Socialite\Contracts\Provider::class);
    $provider->shouldReceive('user')->andReturn($social);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}
