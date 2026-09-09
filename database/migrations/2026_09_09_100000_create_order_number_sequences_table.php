<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counter behind an order number.
 *
 * A stored counter rather than "read the highest existing number and add one",
 * for three reasons:
 *
 *  - **The format can change.** Parsing the last number to find the next one
 *    only works while the format that produced it is still the current one.
 *  - **Deleted orders must not rewind it.** An order number that has been on an
 *    invoice can never be reissued, whatever happens to the row.
 *  - **The WooCommerce import needs a starting point.** The live site has order
 *    numbers already; this is where the new sequence is told to continue from
 *    rather than colliding with them.
 *
 * One row per period, so a daily reset simply starts a new key each day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_number_sequences', function (Blueprint $table): void {
            $table->id();

            // 'all', '2026', '2026-10' or '2026-10-05', depending on the
            // configured reset — see OrderNumberReset::periodKey().
            $table->string('period_key', 32)->unique();

            $table->unsignedInteger('next_value')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};
