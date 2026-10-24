<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An industry's homepage card image is now a reference to a library file (picked
 * with MediaPicker) rather than a direct Spatie upload. The old `card` media
 * collection stays readable as a fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('industries', function (Blueprint $table): void {
            $table->unsignedBigInteger('card_media_id')->nullable();
            $table->foreign('card_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('industries', function (Blueprint $table): void {
            $table->dropForeign(['card_media_id']);
            $table->dropColumn('card_media_id');
        });
    }
};
