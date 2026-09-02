<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user UI state — dismissed cards, remembered filters, and whatever else
 * accumulates.
 *
 * One JSON column rather than a boolean per preference, so the next dismissible
 * thing does not need its own migration. Distinct from CLAUDE.md §5.6's
 * `settings` table, which holds site-wide configuration the client edits; this
 * is private to one account and never client-facing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('preferences')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};
