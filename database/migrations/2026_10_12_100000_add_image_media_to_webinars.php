<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webinar card and background images, as references into the media library.
 *
 * Picked through the media manager rather than uploaded per webinar
 * (docs/media-manager.md §3.1), so replacing the file in the library updates
 * every webinar that uses it. nullOnDelete is the belt to the delete guard's
 * braces: the guard refuses to remove a file still in use, and this keeps a raw
 * delete from leaving a dangling id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->foreignId('card_media_id')->nullable()->after('seats_total')
                ->constrained('media')->nullOnDelete();
            $table->foreignId('banner_media_id')->nullable()->after('card_media_id')
                ->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('card_media_id');
            $table->dropConstrainedForeignId('banner_media_id');
        });
    }
};
