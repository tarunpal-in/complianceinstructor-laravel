<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every use of a coupon — CLAUDE.md §5.4.
 *
 * §8 improvement #2 requires this row be written **inside the order
 * transaction**, so two checkouts racing for the last redemption cannot both
 * take it. The UNIQUE below is what makes that true rather than hoped for: the
 * count is read and the row written under the same lock, and a second attempt
 * against the same order fails at the database.
 *
 * Also the reason a coupon's usage is a query rather than a counter column on
 * `coupons` — a counter drifts the first time a row is deleted or a transaction
 * rolls back, and then the cap silently stops working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();

            // Nullable: §7 P3 has guest checkout, and a guest still redeems.
            // `max_per_user` therefore only constrains signed-in customers,
            // which is the honest limit of what it can promise.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // What the discount actually came to, in cents. Snapshotted, because
            // the coupon can be edited afterwards and this has to keep matching
            // the invoice it appears on.
            $table->unsignedInteger('amount_cents');

            $table->timestamp('redeemed_at');
            $table->timestamps();

            // One redemption per order. The idempotency guard for a retried
            // checkout, and the definition of "used once".
            $table->unique(['coupon_id', 'order_id']);

            $table->index(['coupon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
