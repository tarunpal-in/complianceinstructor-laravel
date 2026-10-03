<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the `passkeys` table, which belonged to Fortify.
 *
 * The table was `vendor:publish` output, created when `laravel/fortify` was
 * installed and never used: no model, no trait on User, and its routes were
 * unreachable because the Fortify provider was never registered. Its create
 * migration also imported `Laravel\Passkeys\Passkeys` to resolve the user model,
 * so once the package came out that migration could not run at all — `migrate:
 * fresh --seed` broke, which §4 rule 1 does not allow.
 *
 * Deleting the create migration alone would have left an orphan table with a
 * foreign key into `users` on every database that had already migrated. This
 * drops it there and does nothing on a fresh one.
 *
 * If passkeys are ever wanted — and they are a genuinely good second factor —
 * they come back with the public auth decision in P5, with their own table
 * written for whatever owns authentication then. See docs/auth.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('passkeys');
    }

    /**
     * Deliberately irreversible.
     *
     * Recreating the table would mean reproducing a schema that depended on a
     * package no longer installed, to hold rows nothing can read. A rollback
     * that leaves the database unable to migrate forward again is worse than one
     * that declines.
     */
    public function down(): void
    {
        // Nothing. See the note above.
    }
};
