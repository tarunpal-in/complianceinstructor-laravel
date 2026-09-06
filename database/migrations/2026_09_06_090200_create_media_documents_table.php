<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extracted document text, kept off the `media` table on purpose.
 *
 * A media grid selects every column of sixty rows to draw sixty thumbnails.
 * Putting a MEDIUMTEXT body on `media` would make that query haul sixty document
 * bodies it never renders, so the text sits in a sidecar joined only when
 * searching or when showing one file.
 *
 * No FULLTEXT index. Tests run on SQLite, which has no such index, and MySQL's
 * default `innodb_ft_min_token_size` of 3 plus its stopword list would quietly
 * drop exactly the short technical terms a compliance catalogue is searched by —
 * "FMLA", "I-9", "OSHA". A LIKE scan over a few thousand rows is well inside
 * what an admin search can afford, and `laravel/scout` is already in the stack
 * (§3) if the catalogue ever outgrows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_documents', function (Blueprint $table): void {
            // The media row is the identity; there is never more than one
            // extraction per file, and deleting the file deletes this with it.
            $table->foreignId('media_id')->primary()
                ->constrained('media')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('page_count')->nullable();
            $table->mediumText('extracted_text')->nullable();

            // Distinguishes "not extracted yet" from "extracted, found nothing",
            // which is the normal and expected result for a scanned PDF.
            $table->timestamp('extracted_at')->nullable();
            $table->string('extract_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_documents');
    }
};
