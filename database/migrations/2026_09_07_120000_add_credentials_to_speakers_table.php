<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-nominals — "CPA · SHRM-SCP" — separate from `title`.
 *
 * design-ui/Webinar Page.pdf styles them as their own line under the role, and
 * they do different work: the role says what someone does, the credentials say
 * why an accrediting body accepts them as an instructor. For a CEU audience the
 * second is most of the purchase decision, so it cannot be buried in a job
 * title.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table): void {
            $table->string('credentials')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table): void {
            $table->dropColumn('credentials');
        });
    }
};
