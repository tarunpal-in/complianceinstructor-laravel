<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing pages — CLAUDE.md §5.6, and half of §2's second leverage decision.
 *
 * The predecessor had a model, a table, a controller, a route set and a Blade
 * form for every page section it could render: HomeBanner, IndexIcon,
 * ChooseBlock, AboutBlock, TrainingCms, FoodSafetyCms, thirty more. Adding a
 * section meant a full vertical slice, which is why it also needed sixteen
 * near-identical SEO screens. This table and `page_sections` replace the lot,
 * and §14 #14 bans going back — "never create a model per page section".
 *
 * Worth ~5 weeks, per §2. The saving is only real if it holds: the moment one
 * section gets its own model because it seemed easier, the pattern is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();

            $table->string('title');
            $table->string('slug')->unique();

            $table->string('status', 16)->default('draft')->index();

            // When it goes live, or went. Null on a draft that has never been
            // published; kept afterwards so "when did this go up" survives a
            // page being taken down and put back.
            $table->timestamp('published_at')->nullable();

            // §5.6 names this. One value for now — a second arrives with the
            // first page that genuinely cannot use the standard frame, rather
            // than being invented in advance.
            $table->string('layout', 32)->default('standard');

            $table->timestamps();
            $table->softDeletes();

            // The public route reads status and date together.
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
