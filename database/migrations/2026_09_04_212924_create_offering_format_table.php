<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which formats an offering grants — docs/webinars-module.md §2.1.
 *
 * The table that makes bundles possible. A row per format, so "Live + Recorded
 * + Transcript" is three rows and GrantEntitlements writes three entitlement
 * rows from them. A new combination is data, not a migration.
 *
 * The unique constraint is load-bearing rather than tidy: a duplicated format
 * on one offering would grant the same entitlement twice and, once membership
 * quotas exist (§5.5), consume two of an allowance for one purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offering_format', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offering_id')->constrained()->cascadeOnDelete();
            $table->string('format');
            $table->timestamps();

            $table->unique(['offering_id', 'format']);
            $table->index('format');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offering_format');
    }
};
