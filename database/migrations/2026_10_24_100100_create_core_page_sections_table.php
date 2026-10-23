<?php

declare(strict_types=1);

use App\Models\CorePage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "extra copy blocks" on a core page — ordered, optionally-titled rich-text
 * sections rendered around the page's data-driven content. Same shape and intent
 * as `webinar_sections`: a reorderable, visibility-toggled repeater edited in
 * Filament, not a model-per-block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_page_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(CorePage::class)->constrained()->cascadeOnDelete();

            $table->string('heading')->nullable();
            $table->longText('body')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);

            $table->timestamps();

            $table->index(['core_page_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_page_sections');
    }
};
