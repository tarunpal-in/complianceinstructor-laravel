<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.3 — accrediting bodies: HRCI, SHRM, CPE, APA.
 *
 * `code` is a plain unique string rather than an enum. §12 has these rules
 * "prescribed by HRCI/SHRM and not negotiable", but the *set* of bodies is
 * commercial: the client adds one by inserting a row, not by shipping a
 * migration. Credits per webinar live on the pivot, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditations', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditations');
    }
};
