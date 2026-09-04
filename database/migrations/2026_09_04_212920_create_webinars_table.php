<?php

declare(strict_types=1);

use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.3, amended by docs/webinars-module.md.
 *
 * Two columns the brief did not have:
 *
 * - `type` (live|recorded) — §3 of the module doc. Not derivable from `status`,
 *   which is lifecycle, and deriving it from the offerings a webinar happens to
 *   sell would make every catalogue listing a join.
 * - `attendee_instructions` — the "Important Notice for Our Live Attendees"
 *   callout. Nullable, because it overrides a site-wide default in `settings`;
 *   most webinars will leave it empty.
 *
 * Images are absent by design: the card thumbnail and the hero banner are two
 * spatie/medialibrary collections, not two columns.
 *
 * Enum columns are stored as short strings and cast to PHP backed enums on the
 * model (§5.1). A native DB enum would make adding a case a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinars', function (Blueprint $table): void {
            $table->id();

            $table->string('type')->default(WebinarType::Live->value)->index();
            $table->string('status')->default(WebinarStatus::Draft->value)->index();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();

            // The five tabbed sections on the detail page.
            $table->longText('description')->nullable();
            $table->longText('overview')->nullable();
            $table->longText('background')->nullable();
            $table->longText('who_should_attend')->nullable();
            $table->longText('why_attend')->nullable();
            $table->longText('session_highlights')->nullable();

            // Overrides settings('webinars.default_instructions') when set.
            $table->longText('attendee_instructions')->nullable();

            $table->foreignId('speaker_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('industry_id')->nullable()->constrained()->nullOnDelete();

            // Null for a recorded webinar: there is no session to schedule.
            // Stored UTC; `timezone` is the zone the admin entered it in, so the
            // form can round-trip it and the public page can label it correctly.
            $table->dateTime('scheduled_at')->nullable()->index();
            $table->string('timezone')->default('America/Los_Angeles');
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            // Null means unlimited. No inventory is enforced yet — see the
            // seat-check note in the analysis of §5.4.
            $table->unsignedInteger('seats_total')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The catalogue's hot path: public webinars of a type, soonest first.
            $table->index(['status', 'type', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinars');
    }
};
