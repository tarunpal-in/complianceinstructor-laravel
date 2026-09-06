<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns on spatie's `media` table — docs/media-manager.md §4.
 *
 * Both are nullable, so a medialibrary upgrade cannot collide with them, and §4
 * rule 1 requires the columns exist in a migration either way.
 *
 * `extracted_text` deliberately does NOT live here: it is a MEDIUMTEXT that
 * would be dragged into every `select *` the Media model makes, which is a
 * 60-thumbnail grid pulling 60 document bodies it will never display. It lives
 * in `media_documents` instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            // sha256 of the file. Indexed because the only thing it is ever used
            // for is "have we already got this?" on upload.
            $table->char('hash', 64)->nullable()->index()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['hash']);
            $table->dropColumn('hash');
        });
    }
};
