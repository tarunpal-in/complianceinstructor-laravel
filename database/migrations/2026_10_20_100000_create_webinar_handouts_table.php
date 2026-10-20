<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Study materials that accompany a webinar — CLAUDE.md §6 / P5.
 *
 * A handout is a supplementary file (a PDF, a spreadsheet, an image) given to a
 * learner alongside what they bought — distinct from `webinar_assets`, which is
 * the purchased deliverable itself (the recording, the transcript). It lives on
 * the private webinar-assets disk (R2 in production) and is reached only through
 * a signed, expiring URL by entitlement (§9), never a guessable path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_handouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('disk_path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['webinar_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_handouts');
    }
};
