<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.4 — **one** order header, carrying its own number.
 *
 * The predecessor split this across `orders` (actually order *lines*),
 * `invoices` (actually the order) and `invoice_items` (a join table duplicating
 * price and quantity), which is why every admin screen read from the wrong
 * model. There is one header here and one line table, and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            // Public-facing, so it is not the auto-increment id (§5.1). Sequential
            // within a year for the client's sanity, unique for everyone else's.
            $table->string('number', 32)->unique();

            // Nullable: §7 P3 has guest checkout, and a guest order still has to
            // exist. The billing snapshot carries who it was for.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 32)->index();

            // Integer cents throughout (§4 rule 6). Never a float, and never a
            // value that arrived in a request (§14 #1).
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            // Frozen at placement: a historic invoice must never change because
            // the customer later edited their address (§5.4).
            $table->json('billing_snapshot')->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('placed_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            // The two questions the admin list asks constantly.
            $table->index(['status', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
