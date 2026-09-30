<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff access as a thing with a lifecycle, rather than a side effect.
 *
 * Until now, admin access was "holds one of the four roles" — so the only way
 * to remove it was to strip the roles, which also erased the record of what
 * somebody had. Nobody could answer "what did Sarah have before she left".
 *
 * Deleting a leaver was worse. Seven columns across six tables point at a staff
 * member — `certificates.issued_by` and `revoked_by`, `payments.recorded_by`,
 * `refunds.revoked_by`, `entitlements.granted_by`, `comments.moderated_by`,
 * `inquiries.assigned_to` — every one of them `nullOnDelete`. Deleting an
 * account silently turned "who issued this credit" into "nobody", which is the
 * opposite of what §9 makes the activity log for.
 *
 * So: deactivate. Access stops, roles stay, history keeps pointing at a real
 * person, and reinstating is one click.
 *
 * On `roles`, `is_system` marks the four §8 roles as seeder-owned.
 * RolePermissionSeeder uses syncPermissions(), so anything edited in the UI on
 * those roles would be silently reverted on the next deploy — two sources of
 * truth disagreeing, which this project has avoided everywhere else. System
 * roles are read-only in the admin and custom ones are the client's entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deactivated_at')->nullable()->after('email_verified_at');

            // Who switched them off, and why. The two questions asked after
            // somebody discovers they cannot log in.
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')
                ->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason')->nullable()->after('deactivated_by');

            // Every panel request reads this, so it is worth an index even at
            // this row count.
            $table->index('deactivated_at');
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('guard_name');

            // What the role is for, in the client's words. The four seeded ones
            // get theirs from UserRole::description(); a custom role has
            // nowhere else to say it.
            $table->string('description')->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deactivated_at']);
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['deactivated_at', 'deactivation_reason']);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['is_system', 'description']);
        });
    }
};
