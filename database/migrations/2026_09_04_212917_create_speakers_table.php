<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.3 — speakers.
 *
 * `photo` is absent by design: portraits go through spatie/medialibrary, which
 * §3 adopts to replace the predecessor's 926 timestamp-prefixed files in
 * public/uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speakers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->text('bio')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speakers');
    }
};
