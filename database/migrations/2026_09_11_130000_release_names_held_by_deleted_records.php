<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Models\MembershipPlan;
use Illuminate\Database\Migrations\Migration;

/**
 * Hands back the slugs and codes that records deleted before today are holding.
 *
 * `ReleasesUniqueNameOnDelete` only fires on a delete, so anything already in
 * the bin keeps its name and keeps blocking it — which is the exact state that
 * reported this: a deleted "Regular" plan still owning `regular`, and no way to
 * create another plan with that slug.
 *
 * Idempotent. A record whose name is already suffixed is skipped, so running
 * this twice changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        MembershipPlan::onlyTrashed()->get()->each->releaseUniqueName();

        Coupon::onlyTrashed()->get()->each->releaseUniqueName();
    }

    /**
     * Nothing to reverse.
     *
     * Rolling back would have to put every name back, and by then another
     * record may legitimately hold it — restoring the record is what reclaims
     * its name, and that is a decision for a person rather than a rollback.
     */
    public function down(): void {}
};
