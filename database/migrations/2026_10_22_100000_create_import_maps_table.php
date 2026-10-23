<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The migration backbone — maps a legacy WordPress/WooCommerce record to the
 * model it became.
 *
 * Two jobs: it makes every importer idempotent (re-running updates the mapped
 * row instead of creating a second — the same guarantee `GrantEntitlement`
 * gives the ledger), and it resolves foreign keys across phases (an order finds
 * its new `user_id` from the legacy `customer_id`, a webinar from a legacy
 * product id). `content_hash` lets a re-run skip a source row that has not
 * changed. Lives in the app database, not the legacy one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_maps', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->default('wp');
            // What kind of legacy thing this is: speaker, webinar, offering,
            // user, order, order_item, payment, coupon, entitlement, …
            $table->string('legacy_type');
            $table->unsignedBigInteger('legacy_id');
            // The model it became (polymorphic).
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            // A hash of the source row, so an unchanged record is skipped on a
            // re-run rather than rewritten.
            $table->string('content_hash')->nullable();
            $table->timestamps();

            $table->unique(['source', 'legacy_type', 'legacy_id']);
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_maps');
    }
};
