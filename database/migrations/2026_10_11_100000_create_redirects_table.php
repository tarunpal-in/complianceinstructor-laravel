<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 301/302 redirects — CLAUDE.md §5.6, client SEO brief §4.
 *
 * The register of "this URL moved to that one", so a link that was earning
 * search equity keeps it when a slug changes or the site migrates off WordPress.
 * The brief is specific about what to avoid — chains, loops, and sweeping every
 * dead page to the homepage — so the shape here exists to make those preventable
 * (see App\Models\Redirect and the resource's save guards).
 *
 * `from_path` is stored normalised: a leading slash, no trailing slash,
 * lowercased, no query. One canonical key per source so the lookup is a single
 * indexed hit and two rows can never both claim the same path. `to_path` is
 * either an internal path (leading slash) or an absolute URL for an off-site
 * destination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();

            // The path that moved. UNIQUE so there is exactly one answer, and
            // indexed because every public request checks it.
            $table->string('from_path')->unique();

            // Where it goes. Internal ("/webinars/foo") or absolute
            // ("https://…") for an external move.
            $table->string('to_path');

            // 301 permanent by default; 302 for a genuinely temporary move. A
            // smallint so the column reads as what it is.
            $table->unsignedSmallInteger('status_code')->default(301);

            // Off switch rather than delete, so a redirect can be paused and its
            // hit history kept.
            $table->boolean('is_active')->default(true);

            // How it got here: typed in, created automatically when a slug
            // changed, or bulk-imported from the old site. Lets the admin tell a
            // migration redirect from a deliberate one.
            $table->string('source', 16)->default('manual');

            // Usage, so the client can see which redirects still earn their keep
            // and retire the ones nothing hits.
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();

            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'from_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
