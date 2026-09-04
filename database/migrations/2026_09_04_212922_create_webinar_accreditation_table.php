<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.3 — which bodies award credit for a webinar, and how much.
 *
 * `credits` is decimal(4,2): half-credits are normal, and §12 notes these
 * numbers are prescribed by the accrediting bodies rather than chosen. Never a
 * float — the same reasoning as money (§4 rule 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_accreditation', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accreditation_id')->constrained()->cascadeOnDelete();
            $table->decimal('credits', 4, 2)->default(0);
            $table->timestamps();

            // One row per body per webinar: two rows would double the credits
            // on a certificate.
            $table->unique(['webinar_id', 'accreditation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_accreditation');
    }
};
