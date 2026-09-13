<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The access ledger — CLAUDE.md §5.2, "the most important design decision in
 * the project".
 *
 * Append-only. Orders write to it, subscriptions write to it, staff write to it
 * for comps and corrections, and every access check is then one indexed query
 * instead of a join across three tables and seventeen boolean columns. A refund
 * revokes access by writing `revoked_at` rather than unwinding four related
 * records.
 *
 * It also settles how membership quotas are counted. §5.5 specifies a
 * `subscription_usage` table with a `used_count` column; that table is
 * deliberately not built (agreed 2026-09-13). A counter drifts the first time a
 * row is deleted or a transaction rolls back — the same reasoning that already
 * made coupon redemptions counted rather than stored — and, more to the point,
 * a counter cannot answer "give back the live session they could not attend",
 * because a number has no idea which session it was. Remaining allowance is
 * therefore what the member was sold minus the rows here that spent it, and
 * crediting one back is a single write.
 *
 * Two columns beyond §5.2's shape, both for §9's audit trail: `revoked_by`,
 * because "who took this away" is the first question anyone asks, and `note`,
 * which §5.2 lists and which carries the reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();

            $table->string('format', 32);

            // Polymorphic by hand rather than morphs(): the three sources are a
            // closed set named by an enum (§4 rule 5), and morphs() would invite
            // a fourth to be added without anyone deciding what it means.
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id')->nullable();

            $table->timestamp('granted_at');

            // When access lapses on its own — the end of a membership term, or
            // a time-limited comp. Null means it does not.
            $table->timestamp('expires_at')->nullable();

            // Taken away early: a refund, or a support credit-back.
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('note')->nullable();

            $table->timestamps();

            // The access check, exactly as §5.2 writes it.
            $table->index(['user_id', 'webinar_id', 'format']);

            // The expiry sweep.
            $table->index('expires_at');

            // "What did this order grant" and "what has this member spent".
            $table->index(['source_type', 'source_id']);

            /*
             * §14 #5 — never fulfil without idempotency. The predecessor had no
             * unique constraint on its gateway payment id, so a replayed return
             * URL double-granted. One grant per source per webinar per format
             * means a replayed webhook or a double-clicked redemption writes
             * nothing the second time.
             *
             * `source_id` is null for a manual grant, and MySQL treats NULLs in
             * a unique index as distinct — so staff can comp the same webinar
             * twice if they mean to, which is right: each is a separate decision
             * by a person.
             */
            $table->unique(
                ['user_id', 'webinar_id', 'format', 'source_type', 'source_id'],
                'entitlements_source_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlements');
    }
};
