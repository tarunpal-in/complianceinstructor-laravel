<?php

declare(strict_types=1);

namespace App\Providers;

use App\Import\Hashing\LegacyAwareHasher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the one-off WordPress/WooCommerce migration.
 *
 * Registers the `legacy` hash driver — the bcrypt-superset that can also verify
 * imported WordPress hashes — so the hashing config's default resolves to it.
 * The import commands themselves are auto-discovered; this only adds the driver,
 * and does nothing else at runtime, so it is harmless to leave registered after
 * the migration is done.
 */
class MigrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving('hash', function (HashManager $manager): void {
            $manager->extend('legacy', fn (): Hasher => new LegacyAwareHasher((array) config('hashing.bcrypt', [])));
        });
    }
}
