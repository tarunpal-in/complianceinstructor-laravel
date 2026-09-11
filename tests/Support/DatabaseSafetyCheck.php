<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Stands between the test suite and a real database.
 *
 * On 2026-09-11 the development database was destroyed. A cached config was
 * present, and a cached config makes Laravel ignore every `<env>` line in
 * phpunit.xml — including `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.
 * The suite therefore resolved the connection out of `.env`, pointed at the
 * development MariaDB, and `RefreshDatabase` did what it always does first:
 * `migrate:fresh`. Every table dropped and re-created empty.
 *
 * The cache was not the bug. The bug was that nothing checked where the suite
 * was about to run before it started dropping tables, so an ordinary mistake
 * had no floor under it. This is that floor.
 *
 * Three independent conditions, because any one of them alone can be got
 * around: a cached config is the specific trigger seen here, a non-testing
 * environment catches the same class of mistake from a stray env var, and the
 * database-name rule catches the case that actually matters however it arose.
 *
 * Deliberately no escape hatch. A flag to skip this is a flag someone sets
 * once while debugging and forgets, which is exactly how it happened.
 */
final class DatabaseSafetyCheck
{
    /**
     * Everything wrong with where the suite is about to run.
     *
     * @return array<int, string>
     */
    public static function problems(
        bool $configurationIsCached,
        string $environment,
        string $connection,
        ?string $database,
    ): array {
        $problems = [];

        if ($configurationIsCached) {
            $problems[] = 'The configuration is cached, so phpunit.xml is being ignored — including DB_CONNECTION and DB_DATABASE. Run: php artisan config:clear';
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

    /** The message a developer sees instead of losing their data. */
    public static function message(array $problems): string
    {
        return "\n  The test suite refused to run.\n\n  "
            .implode("\n  ", array_map(static fn (string $problem): string => '- '.$problem, $problems))
            ."\n\n  RefreshDatabase drops every table before the first test, so this check"
            ."\n  runs before it rather than after.\n";
    }
}
