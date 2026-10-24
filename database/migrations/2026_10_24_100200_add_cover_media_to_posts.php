<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A blog post's cover image is now a reference to a library file (picked with
 * MediaPicker) rather than a direct Spatie upload. The old `cover` media
 * collection stays readable as a fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->foreign('cover_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropForeign(['cover_media_id']);
            $table->dropColumn('cover_media_id');
        });
    }
};
