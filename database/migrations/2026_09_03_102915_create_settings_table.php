<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.6 — `settings`: id, group, key, value JSON, type.
 *
 * Site-wide configuration the client edits: promo bar, brand, contact, social,
 * trust figures, SEO defaults. Distinct from users.preferences, which is private
 * per-account UI state.
 *
 * Values are JSON so a setting can hold a boolean, an integer or a string
 * without a column per type; `type` records which, so the admin form can render
 * the right control and the accessor can cast back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group')->index();
            $table->string('key');
            $table->json('value')->nullable();
            $table->string('type')->default('string');
            $table->timestamps();

            // One row per setting. Also the guard against a double-write
            // creating two rows that disagree.
            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
