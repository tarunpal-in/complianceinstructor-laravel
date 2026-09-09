<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a refund failed, in the gateway's words.
 *
 * `reason` holds the admin's reason for refunding, which is a different thing
 * and must not be overwritten by a decline. A failed refund with no explanation
 * is unactionable — "the card network refused" and "the balance is too low to
 * refund from" need different responses, and only the gateway knows which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->text('gateway_message')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn('gateway_message');
        });
    }
};
