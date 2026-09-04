<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.3 — replaces the eight link/file columns the predecessor kept on
 * its webinars table.
 *
 * One row per deliverable: the live join link, the recording URL, the
 * transcript PDF, the downloadable materials. `url` for something hosted
 * elsewhere (Zoom, a CDN), `disk_path` for a file on the private disk.
 *
 * `available_from` is what releases a join link on schedule (§7 P5) and gates
 * recordings until after the session. §9 requires these reach the customer
 * through signed, expiring URLs by entitlement — never a guessable path — so
 * `disk_path` points at a private disk, not `public/`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->string('format')->index();
            $table->string('label')->nullable();
            $table->text('url')->nullable();
            $table->string('disk_path')->nullable();
            $table->dateTime('available_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['webinar_id', 'format', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_assets');
    }
};
