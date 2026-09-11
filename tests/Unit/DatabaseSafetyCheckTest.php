<?php

declare(strict_types=1);

use Tests\Support\DatabaseSafetyCheck;

/**
 * The guard that would have saved the development database on 2026-09-11.
 *
 * Tested as a pure function so the conditions can be exercised without having
 * to arrange a cached config or a real connection — the thing being asserted is
 * the judgement, and TestCase supplies the three real values to it.
 */

/** The healthy case: nothing is wrong, so nothing is reported. */
it('allows the suite to run in memory', function (): void {
    expect(DatabaseSafetyCheck::problems(false, 'testing', 'sqlite', ':memory:'))->toBe([]);
});

it('allows the database CI and parallel workers use', function (string $database): void {
    expect(DatabaseSafetyCheck::looksLikeATestDatabase($database))->toBeTrue();
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
    $problems = DatabaseSafetyCheck::problems(false, 'testing', 'mariadb', 'ceu_platform');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('ceu_platform')
        ->and($problems[0])->toContain('migrate:fresh');
});

/**
 * The trigger. A cached config makes Laravel ignore phpunit.xml entirely, which
 * is how the connection silently became the development one.
 */
it('refuses to run while the configuration is cached', function (): void {
    $problems = DatabaseSafetyCheck::problems(true, 'testing', 'sqlite', ':memory:');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('php artisan config:clear');
});

it('refuses to run outside the testing environment', function (): void {
    $problems = DatabaseSafetyCheck::problems(false, 'local', 'sqlite', ':memory:');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('"local"');
});

/**
 * The three conditions are independent, so a run that is wrong in every way
 * says so in every way rather than sending someone round the loop three times.
 */
it('reports every reason at once', function (): void {
    expect(DatabaseSafetyCheck::problems(true, 'local', 'mariadb', 'ceu_platform'))->toHaveCount(3);
});

/** A connection with no database name is not a test database by default. */
it('refuses a database it cannot identify', function (?string $database): void {
    expect(DatabaseSafetyCheck::looksLikeATestDatabase($database))->toBeFalse();
})->with([null, '', 'ceu_platform', 'production', 'database.sqlite']);

/** A path whose directory says "test" does not make the file a test database. */
it('judges a SQLite file on its name, not its path', function (): void {
    expect(DatabaseSafetyCheck::looksLikeATestDatabase('/var/www/testing/live.sqlite'))->toBeFalse()
        ->and(DatabaseSafetyCheck::looksLikeATestDatabase('/var/www/live/testing.sqlite'))->toBeTrue();
});

it('spells out what the developer has to do', function (): void {
    $message = DatabaseSafetyCheck::message(DatabaseSafetyCheck::problems(true, 'local', 'mariadb', 'ceu_platform'));

    expect($message)->toContain('The test suite refused to run')
        ->and($message)->toContain('php artisan config:clear')
        ->and($message)->toContain('ceu_platform');
});
