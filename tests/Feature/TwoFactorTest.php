<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function appAuthentication(): AppAuthentication
{
    $providers = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders();

    return collect($providers)->firstOrFail(
        fn (object $provider): bool => $provider instanceof AppAuthentication,
    );
}

// The panel is wired -------------------------------------------------------------------

/*
 * Filament's own provider, not Fortify's. Staff sign in at /admin/login, which
 * is Filament's page and never enters Fortify's pipeline — so Fortify's
 * challenge would never fire however well it was configured.
 */
it('offers app-based two-factor on the admin panel', function (): void {
    expect(appAuthentication())->toBeInstanceOf(AppAuthentication::class);
});

it('offers recovery codes, because a lost phone is the likely failure', function (): void {
    expect(appAuthentication()->isRecoverable())->toBeTrue();
});

/*
 * Optional for now, deliberately. With one Super Admin, requiring a second
 * factor before anybody has set one up is a lockout waiting for a lost phone.
 */
it('offers two-factor without requiring it yet', function (): void {
    expect(Filament::getPanel('admin')->hasMultiFactorAuthentication())->toBeTrue()
        ->and(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired())->toBeFalse();
});

it('lets somebody in without it while it is optional', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin')
        ->assertSuccessful();
});

// The secret ----------------------------------------------------------------------------

/*
 * Filament hashes recovery codes itself but hands the secret over raw — it has
 * to be readable to compute the expected code. Encrypting it is this model's
 * job, and a plain-text secret is a database dump away from every
 * administrator's second factor.
 */
it('never stores the secret in plain text', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $secret = appAuthentication()->generateSecret();

    $admin->saveAppAuthenticationSecret($secret);

    $stored = (string) User::query()->whereKey($admin->getKey())->value('two_factor_secret');

    expect($stored)->not->toBe($secret)
        ->and($stored)->not->toContain($secret)
        ->and($admin->fresh()->getAppAuthenticationSecret())->toBe($secret);
});

/** A secret written under a different APP_KEY reads as "not set up" rather than throwing. */
it('survives a secret it cannot decrypt', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $admin->forceFill(['two_factor_secret' => 'not-encrypted-at-all'])->save();

    expect($admin->fresh()->getAppAuthenticationSecret())->toBeNull();
});

/*
 * Filament has no notion of `two_factor_confirmed_at`; Fortify's migration
 * added it and the staff screen reads it. Without stamping it here the badge
 * would say "Off" for somebody who had just set two-factor up.
 */
it('marks the account as protected once a secret is saved', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);

    expect($admin->hasTwoFactorEnabled())->toBeFalse();

    $admin->saveAppAuthenticationSecret(appAuthentication()->generateSecret());

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('marks it unprotected again when the secret is removed', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $admin->saveAppAuthenticationSecret(appAuthentication()->generateSecret());

    $admin->saveAppAuthenticationSecret(null);

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($admin->fresh()->two_factor_secret)->toBeNull();
});

it('lists the account under the email address', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);

    // Two accounts for one person are told apart in the app by address, not
    // by a name that would read the same twice.
    expect($admin->getAppAuthenticationHolderName())->toBe($admin->email);
});

// Codes ------------------------------------------------------------------------------------

/*
 * The mechanism: both sides compute the same six digits from the shared secret
 * and the clock, and nothing is transmitted. This proves the server's half
 * agrees with what an authenticator app would show.
 */
it('accepts the code an authenticator would be showing right now', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $provider = appAuthentication();

    $admin->saveAppAuthenticationSecret($provider->generateSecret());

    $code = $provider->getCurrentCode($admin->fresh());

    expect($provider->verifyCode($code, $admin->fresh()->getAppAuthenticationSecret()))->toBeTrue();
});

it('refuses a code from a different secret', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $provider = appAuthentication();

    $admin->saveAppAuthenticationSecret($provider->generateSecret());

    $somebodyElse = $provider->generateSecret();
    $wrongCode = $provider->getCurrentCode($admin->fresh(), $somebodyElse);

    expect($provider->verifyCode($wrongCode, $admin->fresh()->getAppAuthenticationSecret()))->toBeFalse();
});

it('refuses six digits somebody made up', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $provider = appAuthentication();

    $admin->saveAppAuthenticationSecret($provider->generateSecret());

    expect($provider->verifyCode('000000', $admin->fresh()->getAppAuthenticationSecret()))->toBeFalse();
});

/*
 * Filament defaults to a window of 8, which accepts a code for four minutes
 * either side. One window still covers ordinary clock drift, and four minutes
 * is a long time for a code somebody read over a shoulder.
 */
it('keeps the accepted window tight', function (): void {
    expect(appAuthentication()->getCodeWindow())->toBe(1);
});

// Recovery -----------------------------------------------------------------------------------

it('stores recovery codes hashed, never readable', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $provider = appAuthentication();

    $codes = $provider->generateRecoveryCodes();
    $provider->saveRecoveryCodes($admin, $codes);

    $stored = $admin->fresh()->getAppAuthenticationRecoveryCodes() ?? [];

    expect($stored)->toHaveCount(count($codes))
        // Hashed by Filament before they reach the model — they are verified,
        // never displayed again.
        ->and($stored)->not->toContain($codes[0]);
});

it('accepts a recovery code once and not twice', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $provider = appAuthentication();

    $codes = $provider->generateRecoveryCodes();
    $provider->saveRecoveryCodes($admin, $codes);

    expect($provider->verifyRecoveryCode($codes[0], $admin->fresh()))->toBeTrue()
        // Spent. Otherwise a code read off a printout stays valid forever.
        ->and($provider->verifyRecoveryCode($codes[0], $admin->fresh()))->toBeFalse();
});

it('refuses a recovery code that was never issued', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $provider = appAuthentication();

    $provider->saveRecoveryCodes($admin, $provider->generateRecoveryCodes());

    expect($provider->verifyRecoveryCode('not-a-real-code', $admin->fresh()))->toBeFalse();
});

// The staff screen agrees ------------------------------------------------------------------------

it('clears everything when an administrator resets a lost device', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);
    $provider = appAuthentication();

    $editor->saveAppAuthenticationSecret($provider->generateSecret());
    $provider->saveRecoveryCodes($editor, $provider->generateRecoveryCodes());

    app(App\Actions\Staff\SetStaffAccess::class)->clearTwoFactor($editor);
    $editor->refresh();

    expect($editor->hasTwoFactorEnabled())->toBeFalse()
        ->and($editor->getAppAuthenticationSecret())->toBeNull()
        ->and($editor->getAppAuthenticationRecoveryCodes())->toBeNull();
});

it('shows who is still unprotected', function (): void {
    $protected = userWithRole(UserRole::Finance);
    $protected->saveAppAuthenticationSecret(appAuthentication()->generateSecret());

    $unprotected = userWithRole(UserRole::Support);

    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin))
        ->test(App\Filament\Resources\Staff\Pages\ListStaff::class)
        ->set('activeTab', 'unprotected')
        ->assertCanSeeTableRecords([$unprotected])
        ->assertCanNotSeeTableRecords([$protected]);
});

// The QR code -------------------------------------------------------------------------------------

/*
 * Filament's own provider double-encodes this wherever the imagick extension
 * is absent — which is most installs, including the §11 VPS this ships to. Its
 * fallback wraps the library's output in a data URI, and the library has
 * already done exactly that, so the browser decodes a data URI whose payload
 * is the text of another data URI. The image breaks; the manual key beside it
 * still works, which makes it read as a rendering quirk rather than a bug.
 *
 * Asserting "it returns a string" would not have caught it. This decodes the
 * thing and checks an authenticator app would find a QR code there.
 */
it('produces a QR code a browser can actually render', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($admin);

    $uri = appAuthentication()->generateQrCodeDataUri(appAuthentication()->generateSecret());

    expect($uri)->toStartWith('data:image/svg+xml;base64,');

    $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

    expect($decoded)->toBeString()
        // The payload is a document, not another data URI.
        ->and($decoded)->not->toStartWith('data:')
        ->and($decoded)->toContain('<svg');
});

it('encodes the details an authenticator app needs', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($admin);

    // The QR is only a carrier for this URL — the account it belongs to, who
    // issued it, and the shared secret. Nothing else is ever transmitted.
    $secret = appAuthentication()->generateSecret();
    $url = app(PragmaRX\Google2FA\Google2FA::class)->getQRCodeUrl(
        'Compliance Instructor',
        $admin->getAppAuthenticationHolderName(),
        $secret,
    );

    expect($url)->toStartWith('otpauth://totp/')
        ->and($url)->toContain($secret)
        ->and($url)->toContain(rawurlencode($admin->email));
});
