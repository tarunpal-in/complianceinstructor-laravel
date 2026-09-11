<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links Cashier's subscription to the plan it was bought from.
 *
 * §5.5 specifies a `subscriptions` table of its own — `membership_plan_id`,
 * `status`, `gateway`, `gateway_subscription_id`, `starts_at`, `ends_at`,
 * `cancelled_at`, `price_cents`. Cashier already publishes a table by that name
 * with Stripe's shape, and §3 gives Cashier "all subscription billing", so
 * there is no version of this where both own the table.
 *
 * Cashier keeps it. What §5.5 adds and Cashier lacks is the link to our own
 * plan and a price snapshot, so those are the only two columns added:
 *
 *  - `gateway` is redundant — §3 makes subscriptions Stripe-only, PayPal being
 *    for one-off orders.
 *  - `status`, `starts_at`, `ends_at`, `cancelled_at` and the gateway id are
 *    Cashier's `stripe_status`, `created_at`, `ends_at` and `stripe_id`.
 *    Duplicating them would give two answers to "is this person subscribed",
 *    and the wrong one would eventually be read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // Nullable: a subscription can exist in Stripe before anyone maps
            // it to a plan, and deleting a plan must not delete the record of
            // what someone paid.
            $table->foreignId('membership_plan_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            // What they are actually being charged, snapshotted at signup so a
            // repriced plan does not rewrite anyone's history.
            $table->unsignedInteger('price_cents')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('membership_plan_id');
            $table->dropColumn('price_cents');
        });
    }
};
