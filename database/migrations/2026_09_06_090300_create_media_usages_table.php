<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a library file is used — docs/media-manager.md §4.
 *
 * Rebuilt, never patched: saving a page deletes that page's rows and reinserts
 * them, so the index cannot drift from the records it describes.
 * `media:reindex-usages` rebuilds the table from scratch if it is ever doubted.
 *
 * This is what makes the delete guard possible. Deleting the homepage hero
 * because nothing said it was in use is the classic media-manager disaster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_usages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->morphs('usable');

            // The field or block key inside the record — 'hero.background',
            // 'brand.logo'. Two blocks on one page using the same image are two
            // usages, which is what the client needs to see.
            $table->string('context');

            $table->timestamps();

            $table->unique(['media_id', 'usable_type', 'usable_id', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_usages');
    }
};
