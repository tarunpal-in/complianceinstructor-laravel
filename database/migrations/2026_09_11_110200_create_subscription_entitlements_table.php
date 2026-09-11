<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one subscriber actually bought.
 *
 * Agreed with Tarun on 2026-09-11: **an active subscriber keeps the terms they
 * signed up under.** Change a plan from unlimited transcripts to four a term
 * and existing members must not quietly lose access they are paying for.
 *
 * Done by snapshot rather than by versioning the plan, because this codebase
 * already answers that question twice the same way: `order_items` freezes the
 * price and `order_item_format` freezes what was granted, so an invoice cannot
 * change under a customer. A subscription is the same kind of promise.
 *
 * It also makes "only active subscribers" fall out for free rather than needing
 * a status check. The snapshot lives on the subscription: when that ends, so
 * does it, and a lapsed member who resubscribes gets a new subscription row and
 * therefore today's terms.
 *
 * §5.2 note for P4: entitlement grants read **these** rows, never
 * `plan_entitlements`. Reading the plan at grant time would undo the whole
 * thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_entitlements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            $table->string('format', 32);
            $table->unsignedSmallInteger('quantity')->nullable();
            $table->string('window', 16);

            $table->timestamps();

            // Same constraint and the same reason as `plan_entitlements`: a
            // duplicated format doubles the allowance.
            $table->unique(['subscription_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_entitlements');
    }
};
