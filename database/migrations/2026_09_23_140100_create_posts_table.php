<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The blog — CLAUDE.md §5.7, and §13's sixth descope candidate.
 *
 * "Useful for SEO, but nothing depends on it" is why it sits on the ladder.
 * That is also exactly why it is worth building properly: §3 puts the whole
 * business on server-rendered SEO, and a blog is the only part of this site
 * that produces new indexable pages without anybody scheduling a webinar.
 *
 * Status and date mirror `pages` and use the same PublishStatus enum, so a
 * draft post and a draft page behave identically — including the signed preview
 * link, which is the same mechanism rather than a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();

            $table->string('title');
            $table->string('slug')->unique();

            // What the index and the meta description show. Kept separate from
            // the body rather than trimmed out of it: an excerpt cut by machine
            // at 160 characters reads like one, and this is the text search
            // engines quote.
            $table->string('excerpt', 500)->nullable();

            $table->longText('body');

            // Nullable so deleting a category does not take its posts with it.
            // An uncategorised post is recoverable; a deleted one is not.
            $table->foreignId('post_category_id')->nullable()->constrained()->nullOnDelete();

            // Who wrote it, for the byline. Nullable for the same reason: a
            // staff account being removed must not delete their articles.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The index page: live posts, newest first.
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
