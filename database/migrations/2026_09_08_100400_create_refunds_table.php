<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.4.
 *
 * A refund is its own row against a payment rather than a mutation of it, so
 * the amount originally taken stays readable forever and partial refunds add up
 * rather than overwrite.
 *
 * §5.2 has the access consequence: refunding revokes by writing `revoked_at` on
 * the entitlements the order granted, not by unwinding related records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            // The same idempotency guard as payments: a replayed refund webhook
            // must not refund twice.
            $table->string('gateway_refund_id')->nullable()->unique();

            $table->string('status', 32)->index();
            $table->unsignedInteger('amount_cents');
            $table->string('reason')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
