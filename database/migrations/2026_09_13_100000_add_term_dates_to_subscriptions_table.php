<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a membership runs from, and when it runs out.
 *
 * Cashier's `ends_at` cannot answer this. It means "cancelled, access until" —
 * null while a membership is healthy — so on a one-off plan, which is what all
 * four of the client's tiers are, nothing recorded that a member who bought
 * Regular on 12 September has access until 12 December. The admin could show
 * when somebody joined and not when they finish.
 *
 * Snapshotted rather than computed from `membership_plans.duration_months`, for
 * the same reason the price and the allowances are (agreed 2026-09-11):
 * recalculating from the plan would move every existing member's end date the
 * first time somebody edited a plan from three months to four.
 *
 * Both nullable. A recurring plan has no end — it renews until cancelled, and
 * `ends_at` takes over at that point — so a null `term_ends_at` means "renews",
 * not "unknown".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('term_starts_at')->nullable()->after('price_cents');
            $table->timestamp('term_ends_at')->nullable()->after('term_starts_at');

            // Indexed because the expiry sweep asks "whose term is over" — a
            // scheduled job in P4, and the list's status filter today.
            $table->index('term_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['term_ends_at']);
            $table->dropColumn(['term_starts_at', 'term_ends_at']);
        });
    }
};
