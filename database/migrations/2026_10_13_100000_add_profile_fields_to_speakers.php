<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable marketing fields for the speaker profile — design-ui/Single Speaker
 * Page.png.
 *
 * Rating, reviews and years-teaching are figures the client enters, the same
 * hand-entered marketing numbers the homepage stat band carries: there is no
 * reviews system to derive a rating from, so making them columns (nullable, so a
 * profile without them simply omits those rows) is honest about where the number
 * comes from. Expertise is the "Areas of expertise" tag list. Webinar counts,
 * enrolment, topic domains and credits are derived, not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table): void {
            $table->decimal('rating', 2, 1)->nullable()->after('bio');
            $table->unsignedInteger('reviews_count')->nullable()->after('rating');
            $table->unsignedSmallInteger('years_teaching')->nullable()->after('reviews_count');
            $table->json('expertise')->nullable()->after('years_teaching');
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table): void {
            $table->dropColumn(['rating', 'reviews_count', 'years_teaching', 'expertise']);
        });
    }
};
