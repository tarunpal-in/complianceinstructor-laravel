<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The two §9 rows that were requirements rather than code.
 *
 * "Production config check asserts debug is off" had no check, and the password
 * policy was the framework floor because `Password::defaults()` was never
 * called — on accounts that can issue refunds and read every invoice in the
 * email log.
 */

// The password policy ------------------------------------------------------------------

/**
 * Validate a password exactly as Filament's reset and profile pages do.
 */
function passwordPasses(string $password): bool
{
    return Validator::make(
        ['password' => $password],
        ['password' => PasswordRule::default()],
    )->passes();
}

it('refuses a password the framework floor would have allowed', function (string $password): void {
    expect(passwordPasses($password))->toBeFalse();
})->with([
    // Eight characters was the whole policy until this was set.
    'password',
    'Passw0rd',
    'abcdefghijkl',       // long enough, no case mix, no digits
    'ABCDEFGHIJKL',
    'Abcdefghijkl',       // case mix, no digit
    'abcdefgh1234',       // digits, no case mix
]);

it('accepts a password that meets the policy', function (): void {
    expect(passwordPasses('Correct-Horse-7-Battery'))->toBeTrue();
});

/*
 * `uncompromised()` asks haveibeenpwned over the network. A rule that fails
 * closed when there is no connection locks people out of their own admin, and in
 * a test run it would make the suite depend on the internet.
 */
it('checks a password against known breaches in production only', function (): void {
    expect(app()->isProduction())->toBeFalse();

    // Reaches the network if the rule is misconfigured, so the assertion is on
    // the environment gate rather than on a breached password.
    expect(passwordPasses('Correct-Horse-7-Battery'))->toBeTrue();
});

/*
 * Both password screens defer to the configured default, which is what makes one
 * `defaults()` call enough to cover the whole application.
 *
 * Matched loosely on purpose: the two pages import the rule under different
 * aliases, and pinning the alias would make this fail on a cosmetic upgrade. If
 * Filament ever stops deferring, that assumption has broken and this should say
 * so — the policy would silently stop applying.
 */
it('leaves both password screens deferring to the configured default', function (string $page): void {
    expect((string) file_get_contents(base_path($page)))
        ->toMatch('/Password(Rule)?::default\(\)/');
})->with([
    'vendor/filament/filament/src/Auth/Pages/PasswordReset/ResetPassword.php',
    'vendor/filament/filament/src/Auth/Pages/EditProfile.php',
]);

/*
 * The drift that would actually bypass the policy: a password field in *our* code
 * with its own rules. One `defaults()` call only helps where the default is used.
 */
it('writes no password rule of its own', function (): void {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->filter(function (SplFileInfo $file): bool {
            $source = (string) file_get_contents($file->getPathname());

            // A hand-rolled minimum, or a Password rule built with anything other
            // than default(). Either sets a second policy.
            return preg_match('/Password::(min|letters|mixedCase|numbers|symbols|uncompromised)\(/', $source) === 1
                || preg_match("/'password'\s*=>\s*\[[^\]]*min:/", $source) === 1;
        })
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        // AppServiceProvider is where the one policy is declared.
        ->reject(fn (string $path): bool => str_contains($path, 'AppServiceProvider.php'))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

// The production check ------------------------------------------------------------------

it('passes outside production', function (): void {
    $this->artisan('app:production-check')->assertSuccessful();
});

/*
 * The local environment genuinely is misconfigured for production — debug on,
 * http URL, mail going to the log — so the command has real failures to find
 * without anything being faked.
 */
it('refuses the local environment as if it were production', function (): void {
    $this->artisan('app:production-check --pretend-production')->assertFailed();
});

it('names debug as a problem', function (): void {
    config(['app.debug' => true]);

    $this->artisan('app:production-check --pretend-production')
        ->expectsOutputToContain('APP_DEBUG is true')
        ->assertFailed();
});

/*
 * The one that would have shipped. `log` accepts every message and discards it,
 * while the email log records it as handed over — so the screen says sent and
 * nobody received anything.
 */
it('names a mailer that throws messages away', function (string $mailer): void {
    config(['mail.default' => $mailer]);

    $this->artisan('app:production-check --pretend-production')
        ->expectsOutputToContain('discards every message')
        ->assertFailed();
})->with(['log', 'array', 'null']);

it('names a sync queue, which would run certificates inside the request', function (): void {
    config(['queue.default' => 'sync']);

    $this->artisan('app:production-check --pretend-production')
        ->expectsOutputToContain('QUEUE_CONNECTION is sync')
        ->assertFailed();
});

it('names an http app url, which every email link is built from', function (): void {
    config(['app.url' => 'http://complianceinstructor.test']);

    $this->artisan('app:production-check --pretend-production')
        ->expectsOutputToContain('APP_URL is')
        ->assertFailed();
});

it('passes a properly configured production environment', function (): void {
    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://complianceinstructor.com',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.username' => 'training@example.com',
        'queue.default' => 'redis',
        'session.driver' => 'redis',
        'cache.default' => 'redis',
    ]);

    $this->artisan('app:production-check --pretend-production')->assertSuccessful();
});

/*
 * §9: "`.env` git-ignored from the first commit … CI fails on a committed
 * secret." This check runs outside production too, which is what makes `composer
 * ci` the thing that enforces it.
 */
it('keeps .env out of git', function (): void {
    expect(file_exists(base_path('.gitignore')))->toBeTrue()
        ->and((string) file_get_contents(base_path('.gitignore')))->toContain('.env');

    $this->artisan('app:production-check')->assertSuccessful();
});
