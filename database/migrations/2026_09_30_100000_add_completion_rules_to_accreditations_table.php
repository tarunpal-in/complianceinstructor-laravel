<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-body certificate rules — §12's "configurable per accrediting body".
 *
 * CompletionRules has carried two global numbers since it was written, and its
 * own docblock claimed they were configurable per body. They were not. A
 * ninety-minute webinar can award HRCI and SHRM credits at once (§5.3's
 * `webinar_accreditation` pivot), and if HRCI allows five minutes to be skipped
 * where SHRM allows ten, a learner watching eighty-two minutes should receive
 * one certificate and not the other. Under one global tolerance they received
 * both — an HRCI certificate that should not exist, against rules §12 calls
 * prescribed and not negotiable.
 *
 * **Columns rather than more settings keys.** `certificates.hrci.*` would mean
 * a developer adding keys and form fields every time the client takes on
 * another body, and a body's rules living in Settings while the body itself
 * lives in Catalogue. The same reasoning made §5.5 store plan allowances as
 * `plan_entitlements` rows instead of seventeen booleans on `membership_plans`:
 * configuration that varies per record belongs on the record.
 *
 * **Nullable is the whole design.** Null means "use the default from Settings",
 * so most bodies are left blank and the client changes one number in one place.
 * A new body starts out inheriting rather than starting out with no rule at
 * all.
 *
 * Only two columns. A `minimum_watch_percent` was proposed and cut: nobody has
 * seen HRCI's or SHRM's actual wording yet, and two ways to express "how much
 * must they watch" means a rule about which one wins. A nullable column added
 * later costs nothing; an unused one costs confusion now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accreditations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('live_delay_hours')->nullable()->after('description');
            $table->unsignedSmallInteger('watch_tolerance_minutes')->nullable()->after('live_delay_hours');
        });
    }

    public function down(): void
    {
        Schema::table('accreditations', function (Blueprint $table): void {
            $table->dropColumn(['live_delay_hours', 'watch_tolerance_minutes']);
        });
    }
};
