<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far through a recording each learner has got.
 *
 * The client's rule, agreed 2026-09-23: a recorded webinar is completed when
 * they have watched to within five or ten minutes of the end, and the
 * instruction was explicit that it should be generous rather than "rigid and
 * reluctant to issue".
 *
 * §5.8 names an `attendance` table for this sort of thing, shaped around a live
 * session — joined_at, left_at, watch_percent. That shape is no longer needed:
 * the live rule certifies everyone holding live access 24 hours afterwards, so
 * there is nothing to measure. What is left is watch progress against a video,
 * which is a different thing, so it gets its own table rather than a live-shaped
 * one with half its columns permanently null.
 *
 * One row per learner per webinar. `furthest_seconds` is what decides
 * completion — the point they reached — while `seconds_watched` accumulates
 * across sittings and is there for reporting rather than for the rule. Somebody
 * who watches the last ten minutes twice has not watched the session, and the
 * two columns are what tells those apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_views', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();

            // The furthest point reached, which is what completion is measured
            // against. Never decreases.
            $table->unsignedInteger('furthest_seconds')->default(0);

            // Time actually spent watching, across every sitting.
            $table->unsignedInteger('seconds_watched')->default(0);

            // Stamped the first time the rule is satisfied, and never moved —
            // "when did they finish it" has one answer.
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('last_watched_at')->nullable();

            $table->timestamps();

            // One progress row per learner per webinar; a second would let two
            // half-watched rows add up to a certificate.
            $table->unique(['user_id', 'webinar_id']);

            // "Who finished this" and "what has this person finished".
            $table->index(['webinar_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_views');
    }
};
