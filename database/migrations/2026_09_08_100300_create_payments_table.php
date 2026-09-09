<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.4 — every attempt to take money, whichever gateway took it.
 *
 * The UNIQUE on `gateway_payment_id` is the idempotency guard, and it is the
 * single most important constraint in this schema. §14 #5: the predecessor had
 * no such constraint, so a replayed return URL double-granted. A duplicate
 * webhook now fails at the database rather than in application code that might
 * one day be refactored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('gateway', 32)->index();

            // Nullable only for a manual payment, which has no gateway to give
            // one. UNIQUE still holds: SQL treats NULLs as distinct, so several
            // manual payments coexist while no gateway id can ever repeat.
            $table->string('gateway_payment_id')->nullable()->unique();

            $table->string('status', 32)->index();

            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('USD');

            // The gateway's own words, kept verbatim for disputes. Never parsed
            // to decide anything — the columns above are what the app reads.
            $table->json('raw_response')->nullable();

            // Who recorded it, for manual payments. A gateway payment has none.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
