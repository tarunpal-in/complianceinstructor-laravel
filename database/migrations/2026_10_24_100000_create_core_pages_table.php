<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed "core" marketing pages — Speakers, Membership, Industries, Contact
 * (CLAUDE.md §6). Each row is the editable hero (and, for membership, the shared
 * CTA) behind a page whose body is otherwise data-driven or a custom view. A
 * fixed, seeded set keyed by `key`; rows are edited, never created or deleted.
 *
 * The hero background is a reference into the media library (`hero_media_id`),
 * the same pattern as the webinar banner and the brand logo — replacing the file
 * updates every page that picked it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();

            $table->string('hero_eyebrow')->nullable();
            $table->string('hero_title');
            $table->text('hero_subtitle')->nullable();

            // Library reference; nulled rather than cascaded so tidying a file
            // away removes the background, never the page.
            $table->foreignId('hero_media_id')->nullable()->constrained('media')->nullOnDelete();

            // The dark tint that keeps hero text legible over a photo.
            $table->boolean('hero_overlay')->default(true);

            // The membership page's shared CTA — one label/target the hardcoded
            // "go to memberships" buttons read. Null on the other pages.
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_pages');
    }
};
