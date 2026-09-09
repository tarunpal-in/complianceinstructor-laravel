<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named seat on an order line — CLAUDE.md §5.4 and §15.
 *
 * This audience buys multiple seats for a team (§1), and the attendee is
 * usually not the purchaser. The seat is what a certificate is eventually
 * issued against, so the name has to be captured at checkout rather than
 * inferred from whoever paid.
 *
 * `entitlement_id` is absent until the entitlements ledger lands in P3; the
 * column arrives with it so the foreign key can be a real constraint (§5.1)
 * rather than an integer pointing at a table that does not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_attendees', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email');

            // Set once the attendee claims their seat by registering. Until then
            // the seat exists and is paid for but belongs to no account.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attendees');
    }
};
