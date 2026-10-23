<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four fixed content columns are now rows in `webinar_sections`, and
 * `background` went with them — it was defined but never shown on the public
 * page, so any such copy becomes an ordinary section. `summary`, `description`
 * and `attendee_instructions` stay: they are metadata and the delivery notice,
 * not page sections. §4 rule 8 — replaced schema is deleted, not left dangling.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'overview',
        'background',
        'who_should_attend',
        'why_attend',
        'session_highlights',
    ];

    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->longText($column)->nullable();
            }
        });
    }
};
