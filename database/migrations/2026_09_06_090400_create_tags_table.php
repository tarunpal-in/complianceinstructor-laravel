<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.7 already plans `tags` and `taggables` for the blog. Building
 * them here means media and posts share one vocabulary instead of the blog
 * growing a second tag system at P7.
 *
 * Tags cut across folders: a file lives in exactly one folder but can carry
 * "2026 campaign" and "accreditation" at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');

            // Namespaces the vocabulary — 'media' tags do not clutter the blog's
            // tag picker. Nullable for tags that genuinely apply to anything.
            $table->string('type')->nullable();

            $table->timestamps();

            // Scoped to the type: a 'media' tag and a 'post' tag may share a
            // slug without colliding.
            $table->unique(['type', 'slug']);
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->morphs('taggable');

            $table->unique(['tag_id', 'taggable_type', 'taggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
