<?php

declare(strict_types=1);

use Tests\Support\TestEnvironmentCheck;

/**
 * The guard that would have saved the development database on 2026-09-11.
 *
 * Tested as a pure function so the conditions can be exercised without having
 * to arrange a cached config or a real connection — the thing being asserted is
 * the judgement, and TestCase supplies the three real values to it.
 */

/** The healthy case: nothing is wrong, so nothing is reported. */
it('allows the suite to run in memory', function (): void {
    expect(TestEnvironmentCheck::problems(false, 'testing', 'sqlite', ':memory:'))->toBe([]);
});

it('allows the database CI and parallel workers use', function (string $database): void {
    expect(TestEnvironmentCheck::looksLikeATestDatabase($database))->toBeTrue();
})->with([
    'ceu_testing',
    // Laravel appends a token per worker under --parallel.
    'ceu_testing_test_1',
    'ceu_testing_test_17',
    'testing.sqlite',
    'CEU_TESTING',
]);

/** The exact database that was lost. */
it('refuses the development database', function (): void {
    $problems = TestEnvironmentCheck::problems(false, 'testing', 'mariadb', 'ceu_platform');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('ceu_platform')
        ->and($problems[0])->toContain('migrate:fresh');
});

/**
 * The trigger. A cached config makes Laravel ignore phpunit.xml entirely, which
 * is how the connection silently became the development one.
 */
it('refuses to run while the configuration is cached', function (): void {
    $problems = TestEnvironmentCheck::problems(true, 'testing', 'sqlite', ':memory:');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('php artisan config:clear');
});

/**
 * Cached routes lose no data, but every test touching a route added since they
 * were cached 404s while the code under test is perfectly correct — a failure
 * that points nowhere near its cause.
 */
it('refuses to run while the routes are cached', function (): void {
    $problems = TestEnvironmentCheck::problems(false, 'testing', 'sqlite', ':memory:', routesAreCached: true);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('php artisan route:clear');
});

it('refuses to run outside the testing environment', function (): void {
    $problems = TestEnvironmentCheck::problems(false, 'local', 'sqlite', ':memory:');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('"local"');
});

/**
 * The three conditions are independent, so a run that is wrong in every way
 * says so in every way rather than sending someone round the loop three times.
 */
it('reports every reason at once', function (): void {
    expect(TestEnvironmentCheck::problems(true, 'local', 'mariadb', 'ceu_platform', routesAreCached: true))
        ->toHaveCount(4);
});

/** A connection with no database name is not a test database by default. */
it('refuses a database it cannot identify', function (?string $database): void {
    expect(TestEnvironmentCheck::looksLikeATestDatabase($database))->toBeFalse();
})->with([null, '', 'ceu_platform', 'production', 'database.sqlite']);

/** A path whose directory says "test" does not make the file a test database. */
it('judges a SQLite file on its name, not its path', function (): void {
    expect(TestEnvironmentCheck::looksLikeATestDatabase('/var/www/testing/live.sqlite'))->toBeFalse()
        ->and(TestEnvironmentCheck::looksLikeATestDatabase('/var/www/live/testing.sqlite'))->toBeTrue();
});

it('spells out what the developer has to do', function (): void {
    $message = TestEnvironmentCheck::message(TestEnvironmentCheck::problems(true, 'local', 'mariadb', 'ceu_platform'));

    expect($message)->toContain('The test suite refused to run')
        ->and($message)->toContain('php artisan config:clear')
        ->and($message)->toContain('ceu_platform');
});
