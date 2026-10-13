<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database cart — CLAUDE.md §5.4.
 *
 * A row per cart, resolved by the logged-in user or, for a guest, a session
 * token. `cart_items.unit_price_cents` is a display snapshot ONLY — §5.4 and
 * §14 #1 require every total to be re-derived from `offerings.price_cents` at
 * checkout, never trusted from what the cart stored or the request sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_token')->nullable()->index();
            $table->char('currency', 3)->default('USD');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offering_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            // Snapshot for display continuity; the real price is re-read from the
            // offering at checkout. Never charged from this column.
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->timestamps();

            // One line per offering per cart; adding again raises the quantity.
            $table->unique(['cart_id', 'offering_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
