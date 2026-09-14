<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credits follow the webinar's length, so they stop being something anyone
 * types.
 *
 * The client's rule, given 2026-09-14: 30 minutes of content is half a credit,
 * the same for every accrediting body. That makes a hand-entered figure per
 * body redundant at best and wrong at worst — set a 90-minute webinar's credits
 * to 2.0 by hand and the certificates say one thing while the session says
 * another.
 *
 * The column stays, nullable, and null now means "use the standard rule".
 * Keeping it as an override rather than dropping it: §12 has these rules
 * prescribed by the bodies and warns they are not ours to invent, so the day
 * one of them awards something different for one session, the escape hatch
 * exists and is visible.
 *
 * Existing rows are cleared to null rather than left alone. They were typed
 * before the rule existed, and leaving them would mean the rule silently did
 * not apply to anything already in the catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinar_accreditation', function (Blueprint $table): void {
            $table->decimal('credits', 4, 2)->nullable()->default(null)->change();
        });

        DB::table('webinar_accreditation')->update(['credits' => null]);
    }

    public function down(): void
    {
        // Back to the old shape. The figures typed before this cannot be
        // recovered, so everything falls to the default.
        DB::table('webinar_accreditation')->whereNull('credits')->update(['credits' => 0]);

        Schema::table('webinar_accreditation', function (Blueprint $table): void {
            $table->decimal('credits', 4, 2)->default(0)->change();
        });
    }
};
