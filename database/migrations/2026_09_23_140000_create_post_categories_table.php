<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog categories — CLAUDE.md §5.7.
 *
 * One category per post rather than many, deliberately. A post belongs
 * somewhere; the things it is *about* are tags, and the existing polymorphic
 * `tags` table already handles those. Letting a post sit in three categories at
 * once makes the blog's own navigation ambiguous — which category page does it
 * belong under — for no gain that tags do not already give.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_categories', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description', 500)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_categories');
    }
};
