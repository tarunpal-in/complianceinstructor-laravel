<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a recording is delivered — CLAUDE.md §6 / P5.
 *
 * Off (the default): the asset opens directly — an external link (Dropbox, a
 * CDN) in a new tab, or a signed download of a file. On: the recording plays
 * inside the site's own player (an R2 upload, or a streaming platform's embed
 * URL). A per-asset choice, since the client mixes hosted links with uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinar_assets', function (Blueprint $table): void {
            $table->boolean('embed')->default(false)->after('disk_path');
        });
    }

    public function down(): void
    {
        Schema::table('webinar_assets', function (Blueprint $table): void {
            $table->dropColumn('embed');
        });
    }
};
