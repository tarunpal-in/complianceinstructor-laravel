<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.5 — a membership tier.
 *
 * The price here drives the pricing page. What Stripe actually charges is
 * whatever `stripe_price_id` points at, and the two can drift — a plan reading
 * $99 while Stripe bills $79 is a real hazard, so the admin surfaces the
 * mismatch rather than assuming they agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->unsignedInteger('price_cents');
            $table->char('currency', 3)->default('USD');

            $table->string('billing_period', 16);

            // How long access lasts on a one-off purchase. Null for a
            // recurring plan, where the billing period already says.
            $table->unsignedSmallInteger('duration_months')->nullable();

            // Stripe owns recurring billing (§3), so a recurring plan needs one
            // of these before anyone can subscribe. Nullable because a plan is
            // usually drafted before the Stripe price exists.
            $table->string('stripe_price_id')->nullable()->unique();

            $table->boolean('is_active')->default(true)->index();

            // Not in §5.5, but every pricing page has a highlighted tier and
            // the alternative is the client asking a developer to move it.
            $table->boolean('is_featured')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
