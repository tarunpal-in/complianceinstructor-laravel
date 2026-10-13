<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a seat to the entitlement it was granted — CLAUDE.md §5.4.
 *
 * The order_attendees migration deliberately left this out "until the
 * entitlements ledger lands in P3". It lands now: a paid order grants one
 * entitlement per claimed seat × format, and the seat records which row is its
 * own so a refund revokes exactly the access that seat bought. Nullable because
 * a guest seat is unclaimed (no user to grant to) until it is registered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_attendees', function (Blueprint $table): void {
            $table->foreignId('entitlement_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_attendees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('entitlement_id');
        });
    }
};
