<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Stands between the test suite and a cached artefact that makes it lie.
 *
 * On 2026-09-11 the development database was destroyed. A cached config was
 * present, and a cached config makes Laravel ignore every `<env>` line in
 * phpunit.xml — including `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.
 * The suite therefore resolved the connection out of `.env`, pointed at the
 * development MariaDB, and `RefreshDatabase` did what it always does first:
 * `migrate:fresh`. Every table dropped and re-created empty.
 *
 * The cache was not the bug. The bug was that nothing checked what the suite
 * was about to run against before it started dropping tables, so an ordinary
 * mistake had no floor under it. This is that floor.
 *
 * A cached *route* file joined the list on 2026-09-12, for the milder version
 * of the same fault: Laravel serves cached routes verbatim, so a newly added
 * Filament resource 404s in every test that touches it while the class itself
 * is perfectly correct. Nothing about that failure points at the cause.
 *
 * Four independent conditions, because any one alone can be got around: the two
 * caches are the specific triggers seen so far, a non-testing environment
 * catches the same class of mistake from a stray env var, and the
 * database-name rule catches the case that actually matters however it arose.
 *
 * Deliberately no escape hatch. A flag to skip this is a flag someone sets
 * once while debugging and forgets, which is exactly how it happened.
 */
final class TestEnvironmentCheck
{
    /**
     * Everything wrong with what the suite is about to run against.
     *
     * @return array<int, string>
     */
    public static function problems(
        bool $configurationIsCached,
        string $environment,
        string $connection,
        ?string $database,
        bool $routesAreCached = false,
    ): array {
        $problems = [];

        if ($configurationIsCached) {
            $problems[] = 'The configuration is cached, so phpunit.xml is being ignored — including DB_CONNECTION and DB_DATABASE. Run: php artisan config:clear';
        }

        if ($routesAreCached) {
            // Milder than the config cache — it loses no data — but it fails in
            // a way that looks like a bug in the code under test.
            $problems[] = 'The routes are cached, so anything added since they were cached will 404 for no visible reason. Run: php artisan route:clear';
        }

        if ($environment !== 'testing') {
            $problems[] = sprintf('The environment is "%s", not "testing", so the suite is reading someone else\'s configuration.', $environment);
        }

        if (! self::looksLikeATestDatabase($database)) {
            $problems[] = sprintf(
                'The "%s" connection points at "%s". RefreshDatabase runs migrate:fresh, which would drop every table in it.',
                $connection,
                $database ?? '(no database)',
            );
        }

        return $problems;
    }

    /**
     * Whether a database is one the suite may destroy.
     *
     * `:memory:` is the local default. Everything else has to say `test` in its
     * name — CI uses `ceu_testing`, and a parallel worker gets that plus
     * `_test_{token}`, so both pass while `ceu_platform` does not.
     *
     * basename() so that a SQLite file is judged on its filename rather than on
     * some directory in its path happening to contain the word.
     */
    public static function looksLikeATestDatabase(?string $database): bool
    {
        if ($database === null || $database === '') {
            return false;
        }

        if ($database === ':memory:') {
            return true;
        }

        return str_contains(strtolower(basename($database)), 'test');
    }

    /**
     * The message a developer sees instead of losing their data, or instead of
     * chasing a failure that has nothing to do with their code.
     *
     * @param  array<int, string>  $problems
     */
    public static function message(array $problems): string
    {
        return "\n  The test suite refused to run.\n\n  "
            .implode("\n  ", array_map(static fn (string $problem): string => '- '.$problem, $problems))
            ."\n\n  Each of these makes the suite test something other than the code in front"
            ."\n  of you, and RefreshDatabase drops every table before the first test — so"
            ."\n  this runs before any of that rather than after.\n";
    }
}
