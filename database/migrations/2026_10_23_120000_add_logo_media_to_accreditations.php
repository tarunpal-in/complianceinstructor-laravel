<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The accrediting body's logo is now a reference to a library file (picked with
 * MediaPicker), the same way a webinar's card/banner image works — rather than a
 * direct Spatie upload on the record. Replacing the file in the library updates
 * every body that uses it, and it sidesteps the browser file-upload path
 * entirely (the admin picks an already-stored file).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accreditations', function (Blueprint $table): void {
            $table->unsignedBigInteger('logo_media_id')->nullable()->after('description');
            $table->foreign('logo_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accreditations', function (Blueprint $table): void {
            $table->dropForeign(['logo_media_id']);
            $table->dropColumn('logo_media_id');
        });
    }
};
