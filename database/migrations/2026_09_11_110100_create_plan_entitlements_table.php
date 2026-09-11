<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a plan grants — CLAUDE.md §5.5.
 *
 * "Replaces 17 boolean/counter columns with rows. Adding a '4 live + unlimited
 * transcripts' tier stops being a migration." That is the entire point of this
 * table, and it is why the admin gets a repeater rather than a fixed form.
 *
 * These rows are a **template**. They describe what a *new* subscriber gets;
 * once someone subscribes their allowances are copied to
 * `subscription_entitlements` and this table stops applying to them. Editing a
 * plan therefore never changes what an existing member already paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_entitlements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('membership_plan_id')->constrained()->cascadeOnDelete();

            $table->string('format', 32);

            // NULL means unlimited (§5.5). Not 0 and not -1: both are numbers
            // that arithmetic will happily use, and "unlimited" is not a
            // quantity.
            $table->unsignedSmallInteger('quantity')->nullable();

            $table->string('window', 16);

            $table->timestamps();

            // Load-bearing, and not in §5.5. Two `live` rows on one plan would
            // silently double the allowance — the same defect `offering_format`
            // and `order_item_format` each carry this constraint to prevent.
            $table->unique(['membership_plan_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
    }
};
