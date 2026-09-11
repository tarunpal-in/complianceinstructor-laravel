<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\DatabaseSafetyCheck;

abstract class TestCase extends BaseTestCase
{
    /**
     * Check where we are before anything is allowed to drop a table.
     *
     * setUpTraits() is the hook rather than setUp(), and the ordering is the
     * whole point: the application is booted by now, so the resolved connection
     * can be read, but RefreshDatabase has not run yet — its migrate:fresh
     * happens inside the call below. A check placed after this would report the
     * damage instead of preventing it.
     */
    protected function setUpTraits(): array
    {
        $this->refuseToRunAgainstARealDatabase();

        return parent::setUpTraits();
    }

    private function refuseToRunAgainstARealDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}.database");

        $problems = DatabaseSafetyCheck::problems(
            $this->app->configurationIsCached(),
            (string) config('app.env'),
            $connection,
            is_string($database) ? $database : null,
        );

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(DatabaseSafetyCheck::message($problems));
    }
}
