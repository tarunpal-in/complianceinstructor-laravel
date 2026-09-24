<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captured leads — §5.7, and the "leads" half of §6's Quiz & leads module.
 *
 * Kept apart from `inquiries` and `subscribers`, which §5.7 lists separately
 * and which are different things: an inquiry is a question expecting an answer,
 * a subscriber is a mailing list membership, and a lead is somebody who showed
 * commercial interest and is worth ringing back. Collapsing the three would
 * mean one screen where a support question, a newsletter signup and a sales
 * opportunity all look the same and none of them gets handled properly.
 *
 * **Email is not unique.** The same person filling in two forms three months
 * apart is two leads, because the second one is evidence about a campaign that
 * the first cannot carry. The register counts the repeats instead, and the
 * index below is what makes that cheap.
 *
 * **Nothing here records whether they became a customer.** That is derived by
 * matching this email to an account with a settled order — see Lead::customer.
 * A `converted` flag is a thing somebody has to remember to set, and §8's
 * funnel report would be silently wrong the first time they did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('email');
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();
            $table->string('phone', 40)->nullable();

            // What they train for. Drives which webinars are worth pitching,
            // and it is the one piece of qualifying information this audience
            // volunteers without being asked.
            $table->foreignId('industry_id')->nullable()->constrained()->nullOnDelete();

            $table->string('source', 16)->index();

            /*
             * Which quiz, which guide, which webinar — a label for a person
             * reading the row, never a key. The quiz engine is P7 and does not
             * exist, so a polymorphic id here would point at nothing and commit
             * to a shape nobody has specified yet. When quizzes land, this
             * becomes a real relation and this column becomes its fallback.
             *
             * §14 #8 is the rule it must not break: a string that looks like it
             * joins to something is worse than a string that plainly does not.
             */
            $table->string('source_detail')->nullable();

            $table->string('status', 16)->default('new');

            // Somebody has to own it or nobody rings them.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contacted_at')->nullable();

            $table->text('message')->nullable();
            $table->text('note')->nullable();

            /*
             * Consent, recorded from the first row rather than retrofitted.
             *
             * This client sells compliance training to compliance
             * professionals; being unable to say which of these people agreed
             * to be emailed is exactly the problem they teach other people to
             * avoid. Defaults to false, so a row that never asked the question
             * does not silently claim an answer.
             */
            $table->boolean('marketing_opt_in')->default(false);

            // §8 again: revenue attributed to a campaign, which means carrying
            // the campaign from the click that produced the lead.
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The working queue, oldest first.
            $table->index(['status', 'created_at']);

            // "Have we heard from this person before", and the join to an
            // account when asking whether they ever bought anything.
            $table->index('email');

            // One person's list.
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
