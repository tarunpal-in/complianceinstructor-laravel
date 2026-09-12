<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\TestEnvironmentCheck;

abstract class TestCase extends BaseTestCase
{
    /**
     * Check what we are running against before anything drops a table.
     *
     * setUpTraits() is the hook rather than setUp(), and the ordering is the
     * whole point: the application is booted by now, so the resolved connection
     * can be read, but RefreshDatabase has not run yet — its migrate:fresh
     * happens inside the call below. A check placed after this would report the
     * damage instead of preventing it.
     */
    protected function setUpTraits(): array
    {
        $this->refuseToRunAgainstTheWrongThing();

        return parent::setUpTraits();
    }

    private function refuseToRunAgainstTheWrongThing(): void
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}.database");

        $problems = TestEnvironmentCheck::problems(
            $this->app->configurationIsCached(),
            (string) config('app.env'),
            $connection,
            is_string($database) ? $database : null,
            $this->app->routesAreCached(),
        );

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(TestEnvironmentCheck::message($problems));
    }
}
