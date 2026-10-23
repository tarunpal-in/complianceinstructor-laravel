<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A structured FAQ for a core page, rendered as the native-<details> accordion
 * the page-builder `faq` block uses. Stored as JSON question/answer rows rather
 * than free rich text, so it keeps the accordion design instead of being prose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_pages', function (Blueprint $table): void {
            $table->json('faq')->nullable()->after('cta_url');
        });
    }

    public function down(): void
    {
        Schema::table('core_pages', function (Blueprint $table): void {
            $table->dropColumn('faq');
        });
    }
};
