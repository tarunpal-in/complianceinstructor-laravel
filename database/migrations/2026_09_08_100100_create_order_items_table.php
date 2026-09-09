<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.4, with two deliberate departures.
 *
 * **`webinar_id` is nullable.** §5.4 has it as a plain column, but an offering
 * may be a package, and a package line belongs to no single webinar. Requiring
 * it would either forbid selling packages or invite a sentinel value.
 *
 * **`format` is gone**, to `order_item_format`. §5.4 gives the line one format;
 * docs/webinars-module.md §2.1 established that an offering sells several, and
 * a line has to record every one it granted or a refund cannot revoke exactly
 * what was given.
 *
 * The price columns are a snapshot, not a lookup. §5.3 makes `offerings` the
 * only place a price is *read* from, but once an order is placed the amount
 * charged has to survive the offering being repriced afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Nullable rather than cascading: deleting an offering must not
            // rewrite what a customer was charged. `description` carries the
            // label so the line still reads correctly afterwards.
            $table->foreignId('offering_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('webinar_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot of the offering's composed label at the time of sale.
            $table->string('description');
            $table->string('sku', 64)->nullable();

            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents');

            // §14 #7 — the predecessor stored a unit price in a column called
            // `total_price`, so every revenue sum was wrong by a factor of
            // quantity. Both are stored, and the name means what it says.
            $table->unsignedInteger('line_total_cents');

            $table->unsignedSmallInteger('seats_per_unit')->default(1);

            $table->timestamps();

            $table->index(['order_id', 'webinar_id']);
        });

        Schema::create('order_item_format', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('format', 32);

            // Load-bearing, exactly as on `offering_format`: a duplicate row
            // would fan out into a duplicate entitlement.
            $table->unique(['order_item_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_format');
        Schema::dropIfExists('order_items');
    }
};
