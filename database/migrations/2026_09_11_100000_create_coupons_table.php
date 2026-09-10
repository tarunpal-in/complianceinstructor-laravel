<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.4 and §8 improvement #2, with two departures noted below.
 *
 * §14 #3 is the rule this table exists under: coupon validation is server-side
 * only, and the table is never serialised into a page. The predecessor embedded
 * `Coupon::all()` — every code, value and expiry — in the checkout HTML, so
 * anyone reading the source got the whole discount list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();

            // Compared case-insensitively in the application, stored upper.
            $table->string('code', 40)->unique();
            $table->string('description')->nullable();

            /*
             * §5.4 gives this a `type` enum and one `value` column. Split in
             * two instead: a single column holding either a percentage or an
             * amount of money is the unit confusion §4 rule 6 exists to
             * prevent, and it would have to be named either `value` or
             * `value_cents` — one of which is a lie half the time.
             *
             * Exactly one is set; the model derives the type from which.
             */
            $table->unsignedTinyInteger('percent_off')->nullable();
            $table->unsignedInteger('amount_off_cents')->nullable();
            $table->char('currency', 3)->default('USD');

            // §8 improvement #2 — the caps the predecessor had none of.
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('max_per_user')->nullable();
            $table->unsignedInteger('minimum_spend_cents')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            /*
             * Scope. §5.4 has one `scope_type`/`scope_id` pair; this is a
             * polymorphic target plus a separate format, because "industry" and
             * "webinar" are records while a format is an enum, and putting an
             * id and a format string in one column is exactly the loose-string
             * comparison §14 #12 bans.
             *
             * Both null means the whole catalogue. Both set means AND, so
             * "20% off HR transcripts" is expressible without another table.
             */
            $table->nullableMorphs('scopeable');
            $table->string('scope_format', 32)->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();

            // The list's default view: what is live right now.
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
