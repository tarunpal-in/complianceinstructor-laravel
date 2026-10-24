<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A speaker's portrait is now a reference to a library file (picked with
 * MediaPicker), the same way a webinar's card/banner and an accreditation logo
 * work — rather than a direct Spatie upload on the record. The old `portrait`
 * media collection stays readable as a fallback, so portraits uploaded before
 * this still show until they are re-picked from the library.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table): void {
            $table->unsignedBigInteger('photo_media_id')->nullable();
            $table->foreign('photo_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table): void {
            $table->dropForeign(['photo_media_id']);
            $table->dropColumn('photo_media_id');
        });
    }
};
