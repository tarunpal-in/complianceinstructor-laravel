<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.3 — industries. Also the second segment of a webinar's
 * breadcrumb, `Home › Live Webinars › Human Resources › Title`, so the slug is
 * part of the public URL and must stay unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industries');
    }
};
