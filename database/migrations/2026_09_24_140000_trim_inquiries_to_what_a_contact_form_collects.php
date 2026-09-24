<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the columns that only made sense while this table was called `leads`.
 *
 * The register was designed around captured marketing leads — somebody who
 * traded their job title and industry for a checklist, arriving with a campaign
 * attached. It is now the website's contact form, and a contact form asks for a
 * name, an email, maybe a company and a phone number, and then a message. Every
 * column below was either never going to be filled or belongs somewhere else:
 *
 * - `job_title`, `industry_id` — nobody types a job title into a contact form,
 *   and the industry is a qualifying question a quiz asks, not a form.
 * - `utm_source`, `utm_medium`, `utm_campaign` — campaign attribution for
 *   lead-gen. `source` already answers "where did this come from" at the
 *   granularity this business acts on.
 * - `marketing_opt_in` — the earlier reasoning was that consent is painful to
 *   retrofit, which is true, but it was reasoning about a marketing list. A
 *   contact form's "keep me updated" tickbox belongs on `subscribers`, which
 *   §5.7 already has and which is what would actually be mailed. A consent flag
 *   here would be a second, quieter record of the same permission, and two
 *   records of one permission is how a business ends up unable to say what
 *   somebody agreed to.
 *
 * `source_detail` survives under a better name. On a contact form it is the
 * subject line, which is the single most useful thing in a list of messages; on
 * a quiz it is the quiz's name. "Which one" described neither.
 *
 * Nothing kept here is speculative: name, email, company, phone, subject,
 * message, and the handling columns a reply needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            // Its own statement: dropping a constrained column needs the
            // foreign key gone first, and SQLite will not infer that.
            $table->dropConstrainedForeignId('industry_id');
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropColumn([
                'job_title',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'marketing_opt_in',
            ]);
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->renameColumn('source_detail', 'subject');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->renameColumn('subject', 'source_detail');
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->foreignId('industry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
        });
    }
};
