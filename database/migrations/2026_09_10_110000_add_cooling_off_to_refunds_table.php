<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cooling-off window on a refund.
 *
 * A refund is agreed, everyone with the authority to stop it is emailed, and
 * only then — an hour later by default — does it reach the gateway. The delay
 * is worth nothing without a way to use it, so the cancellation is recorded
 * here too: who stopped it and when, because "why did this refund not go out"
 * is exactly the question a finance dispute turns into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            // When the window closes. Indexed because a scheduled command sweeps
            // for due refunds every minute and this is the whole of its filter.
            $table->timestamp('scheduled_for')->nullable()->after('status')->index();

            $table->timestamp('cancelled_at')->nullable()->after('processed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropIndex(['scheduled_for']);
            $table->dropColumn(['scheduled_for', 'cancelled_at']);
        });
    }
};
