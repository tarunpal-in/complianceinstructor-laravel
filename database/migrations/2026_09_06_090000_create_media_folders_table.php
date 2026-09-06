<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folders own every library file — see docs/media-manager.md §3.
 *
 * Spatie's `media` rows must belong to a model, and a library file belongs to
 * nothing in particular. Making a folder the owner keeps one media table rather
 * than bolting a second one alongside it, and gives the client the filing system
 * they expect for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            // Nullable rather than a root row: a null parent is the top level.
            // The UI allows one level of nesting — deep trees in a media manager
            // are where clients lose files.
            $table->foreignId('parent_id')->nullable()
                ->constrained('media_folders')
                ->nullOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};
