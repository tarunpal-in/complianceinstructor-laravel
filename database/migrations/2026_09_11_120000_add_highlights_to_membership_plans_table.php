<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tick-and-cross list on a pricing card.
 *
 * Deliberately not entitlements. `plan_entitlements` is what a plan *grants* —
 * it is read at signup and decides access, so every row in it has to be true.
 * The pricing card also carries lines like "Certificate of Completion",
 * "Dedicated Account Manager" and "CEU Credits (All Providers)", which are
 * marketing copy: nothing in the app enforces them, and inventing entitlement
 * formats for them would put unenforceable claims into the table that decides
 * who may watch what.
 *
 * So they are stored as what they are — an ordered list of
 * `{label, included}` the client edits themselves. The quota badge above the
 * list stays derived from the entitlements, because that one *is* a promise.
 *
 * The gap this does not close, carried from HomeController: "SHRM & HRCI" on
 * the lower tiers versus "All Providers" on the upper two is a plan-level
 * accreditation scope. As a bullet it is a sentence; nothing enforces it. If
 * P4 has to enforce it, that needs a real column and the §17 offering matrix
 * has to say so first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table): void {
            $table->json('highlights')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table): void {
            $table->dropColumn('highlights');
        });
    }
};
