<?php

declare(strict_types=1);

use App\Actions\Staff\InviteStaffMember;
use App\Enums\EmailStatus;
use App\Enums\UserRole;
use App\Models\EmailSend;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

/**
 * What owns authentication — see docs/auth.md.
 *
 * Fortify was removed on 2026-10-02: installed, never registered in
 * `bootstrap/providers.php`, and unregisterable without opening an unconditional
 * `POST /login` that authenticates on the same session without reaching
 * Filament's two-factor challenge.
 *
 * §9's requirement did not change, so these assert it against the code that
 * actually runs rather than against a package name in the brief.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// There is no public authentication surface ------------------------------------------------

/*
 * §9: "no hand-rolled reset endpoint exists to get wrong". The strongest form of
 * that is no public auth endpoint existing at all, which is the state today — the
 * only way in is /admin/login.
 */
it('exposes no public authentication route', function (string $name): void {
    expect(Route::has($name))->toBeFalse(
        "Route [{$name}] exists. If public auth is being built, docs/auth.md needs updating first."
    );
})->with([
    'login',
    'login.store',
    'register',
    'register.store',
    'password.request',
    'password.email',
    'password.reset',
    'password.update',
    'two-factor.login',
    'verification.verify',
]);

/*
 * The predecessor's second critical finding was an unauthenticated bulk user
 * import — "routes declared outside the middleware group". A public
 * `POST /register` is the same shape, and enabling Fortify would have added one.
 */
it('creates no account from an unauthenticated request', function (string $path): void {
    $response = $this->post($path, [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password-password',
        'password_confirmation' => 'password-password',
    ]);

    /*
     * 405 rather than 404 for most of these, and that is the right answer: the
     * page catch-all `GET /{slug}` claims the path, so a POST to it is
     * method-not-allowed. What matters is that nothing handles the POST and
     * nothing is written.
     */
    expect($response->status())->toBeIn([404, 405])
        ->and(User::query()->where('email', 'intruder@example.com')->exists())->toBeFalse();
})->with(['/register', '/login', '/forgot-password', '/reset-password']);

/*
 * Filament's own login is the only way in, and it is the page that carries the
 * two-factor challenge. A second one authenticating on the same session is the
 * specific reason Fortify came out rather than being switched on.
 */
it('leaves the panel login as the only way to sign in', function (): void {
    $this->get('/admin/login')->assertSuccessful();

    expect(Route::has('filament.admin.auth.login'))->toBeTrue();
});

it('registers no Fortify service provider', function (): void {
    expect(class_exists(Laravel\Fortify\FortifyServiceProvider::class))->toBeFalse()
        ->and(file_exists(config_path('fortify.php')))->toBeFalse()
        ->and(is_dir(app_path('Actions/Fortify')))->toBeFalse();
});

// Reset tokens still meet §9 --------------------------------------------------------------

/*
 * The four properties §9 asks for, asserted against Laravel's broker, which is
 * what has actually been issuing them all along.
 */
it('stores a reset token hashed, never in the clear', function (): void {
    $user = User::factory()->create(['email' => 'dana@example.com']);

    $token = Password::broker()->createToken($user);

    $stored = DB::table('password_reset_tokens')->where('email', 'dana@example.com')->value('token');

    expect($stored)->not->toBeNull()
        // A readable token column would let anybody with database access take
        // every account, which is §9's first critical finding by another route.
        ->and($stored)->not->toBe($token)
        ->and(Hash::check($token, (string) $stored))->toBeTrue();
});

it('accepts a reset token once and then never again', function (): void {
    $user = User::factory()->create(['email' => 'dana@example.com']);
    $token = Password::broker()->createToken($user);

    $first = Password::broker()->reset(
        ['email' => 'dana@example.com', 'password' => 'a-new-password-here', 'token' => $token],
        fn (User $user, string $password) => $user->forceFill(['password' => $password])->save(),
    );

    $second = Password::broker()->reset(
        ['email' => 'dana@example.com', 'password' => 'another-password-x', 'token' => $token],
        fn (User $user, string $password) => $user->forceFill(['password' => $password])->save(),
    );

    expect($first)->toBe(Password::PASSWORD_RESET)
        ->and($second)->toBe(Password::INVALID_TOKEN);
});

it('expires a reset token', function (): void {
    $user = User::factory()->create(['email' => 'dana@example.com']);
    $token = Password::broker()->createToken($user);

    $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

    expect(Password::broker()->reset(
        ['email' => 'dana@example.com', 'password' => 'a-new-password-here', 'token' => $token],
        fn (User $user, string $password) => $user->forceFill(['password' => $password])->save(),
    ))->toBe(Password::INVALID_TOKEN);
});

it('throttles repeated reset requests', function (): void {
    User::factory()->create(['email' => 'dana@example.com']);

    expect(Password::broker()->sendResetLink(['email' => 'dana@example.com']))
        ->toBe(Password::RESET_LINK_SENT)
        // §9: "rate limiting on login, registration, reset". config/auth.php sets
        // 60 seconds, and the broker refuses inside that window.
        ->and(Password::broker()->sendResetLink(['email' => 'dana@example.com']))
        ->toBe(Password::RESET_THROTTLED);
});

/*
 * The one place in the application that issues a token by hand. It must stay on
 * the broker — a token minted anywhere else would be the hand-rolled endpoint §9
 * bans, with none of the four properties above.
 */
it('issues the staff invitation token through the broker', function (): void {
    app(InviteStaffMember::class)('Dana Whitfield', 'dana@example.com', [UserRole::Support->value]);

    $row = DB::table('password_reset_tokens')->where('email', 'dana@example.com')->first();

    expect($row)->not->toBeNull();

    $send = EmailSend::query()->where('to_email', 'dana@example.com')->sole();

    expect($send->email_key)->toBe('internal.staff_invitation')
        ->and($send->status)->toBe(EmailStatus::Sent);
});

/*
 * Nothing on any screen may write a password for somebody else. §9's first
 * critical finding was an endpoint that did exactly that, and the guard is that
 * no such code path exists rather than that no screen offers it.
 */
it('sets no password anywhere in the staff flow', function (): void {
    $sources = [
        app_path('Actions/Staff/InviteStaffMember.php'),
        app_path('Actions/Staff/SetStaffAccess.php'),
        app_path('Filament/Resources/Staff/Schemas/StaffForm.php'),
        app_path('Filament/Resources/Staff/Pages/InviteStaff.php'),
        app_path('Filament/Resources/Staff/Pages/EditStaff.php'),
    ];

    foreach ($sources as $source) {
        $code = (string) file_get_contents($source);

        // Str::random(64) in InviteStaffMember is the one write, and it is a
        // throwaway nobody has — the deliberate shape of "not set yet".
        expect($code)->not->toContain('Hash::make')
            ->and($code)->not->toContain("'password' => \$");
    }
});
