<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sellable SKU — CLAUDE.md §5.3, amended by docs/webinars-module.md §2.
 *
 * "the ONLY place a price is ever read from". §14 #1 bans taking a price,
 * discount or total from the request; every total is recomputed from these rows
 * inside the order transaction.
 *
 * Two departures from the brief:
 *
 * - **No `format` column.** The purchase panel sells seven options and four are
 *   bundles ("Recorded + Transcript", "Live + Recorded + Transcript"). Since
 *   §5.2 keys entitlements on (user, webinar, format), a bundle must grant
 *   several — so formats move to the `offering_format` pivot and
 *   GrantEntitlements loops them. One format per offering would mean a customer
 *   pays $279 and receives one format.
 * - **`addon_price_cents`.** The cart offers "Add Recorded for $55" against
 *   $199 standalone. Nullable: null means this offering is not sold as an
 *   add-on. Which price applies is decided server-side from these two columns,
 *   never from the request.
 *
 * `offerable` is polymorphic over webinars and packages, so it carries no
 * foreign key — the one place §5.1's "real constraints" rule cannot apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offerings', function (Blueprint $table): void {
            $table->id();

            $table->morphs('offerable');

            $table->string('sku')->unique();

            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('compare_at_cents')->nullable();

            // The reduced price when the buyer already has this webinar in the
            // cart under another offering. Null = not offered as an add-on.
            $table->unsignedInteger('addon_price_cents')->nullable();

            $table->char('currency', 3)->default('USD');

            // How many attendees one unit covers, for multi-seat B2B orders.
            $table->unsignedSmallInteger('seats_per_unit')->default(1);

            $table->boolean('is_active')->default(true);

            // The purchase panel lists options in a deliberate order.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['offerable_type', 'offerable_id', 'is_active', 'sort_order'], 'offerings_panel_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offerings');
    }
};
