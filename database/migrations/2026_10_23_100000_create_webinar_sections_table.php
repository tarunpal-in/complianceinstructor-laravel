<?php

declare(strict_types=1);

use App\Models\Webinar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flexible webinar content sections — replaces the four fixed content columns
 * (overview, session_highlights, why_attend, who_should_attend) with rows the
 * admin can rename, reorder, add to, remove, or leave heading-less.
 *
 * Same shape and intent as `page_sections` (§5.6): ordered, visibility-toggled
 * content blocks, edited through a Filament repeater. A heading-less section is
 * how the migrated "you will get the recording link within 24–48 hours" notice
 * lands without being forced under an audience heading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Webinar::class)->constrained()->cascadeOnDelete();

            // Null / empty is a deliberate state: a section with body but no
            // heading. Such a section renders its body but gets no page tab,
            // since a tab needs a label.
            $table->string('heading')->nullable();
            $table->longText('body')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);

            $table->timestamps();

            $table->index(['webinar_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_sections');
    }
};
