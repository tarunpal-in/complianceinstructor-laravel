<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The blocks a page is made of — CLAUDE.md §5.6.
 *
 * Rows rather than one JSON column on `pages`, which Filament's Builder would
 * have given for free. §2 names this table specifically, and the reason holds
 * up: a section that is a row can be asked about. "Which pages use the webinar
 * carousel" is a query rather than a scan through serialised blobs, and that
 * question gets asked the first time a block type has to change.
 *
 * `type` is a string rather than a foreign key to anything. Block types are
 * code — a Blade partial and a form schema — not data, so there is nothing for
 * it to point at. An unknown type renders nothing rather than breaking the
 * page, because a block removed from the code must not take a live marketing
 * page down with it.
 *
 * `data` holds whatever that block's schema produced. Deliberately untyped at
 * the database level: the whole point is that adding a field to a block costs a
 * form change and nothing else. §14 #14 — never a model per section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_sections', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('page_id')->constrained()->cascadeOnDelete();

            $table->string('type', 64);
            $table->json('data')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            // Turning a block off beats deleting it: the copy survives, and the
            // client can put a seasonal block back without retyping it.
            $table->boolean('is_visible')->default(true);

            $table->timestamps();

            // How a page is drawn: its blocks, in order.
            $table->index(['page_id', 'sort_order']);

            // "Where is this block type used", which is the question rows exist
            // to make answerable.
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_sections');
    }
};
