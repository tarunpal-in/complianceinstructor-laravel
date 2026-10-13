<?php

declare(strict_types=1);

namespace App\Actions\Entitlements;

use App\Enums\EntitlementSource;
use App\Models\Entitlement;
use App\Models\Order;

/**
 * Takes back the access an order bought, when that order is fully refunded —
 * CLAUDE.md §5.2 / §10.
 *
 * The mirror of GrantEntitlementsForOrder: that wrote one row per claimed seat ×
 * format with source Order and the order id, and this revokes exactly those rows
 * — found by the same (source_type, source_id) tuple, nothing re-derived. "A
 * refund revokes access by writing `revoked_at` rather than unwinding four
 * related records."
 *
 * Only a *full* refund reaches here. A partial refund is by amount and cannot be
 * attributed to a particular seat, so access is left standing; an admin revokes
 * a specific grant by hand if the partial refund was for a named seat. Each
 * revoke is idempotent at the row level — an already-revoked grant (an admin got
 * there first) is skipped rather than throwing, because this runs from a sweep
 * where a thrown error would strand the rest.
 */
class RevokeEntitlementsForOrder
{
    public function __construct(private readonly RevokeEntitlement $revoke) {}

    /**
     * @return int how many grants were taken back
     */
    public function __invoke(Order $order, string $reason, ?int $revokedBy = null): int
    {
        $grants = Entitlement::query()
            ->fromSource(EntitlementSource::Order, $order->getKey())
            ->notRevoked()
            ->get();

        $revoked = 0;

        foreach ($grants as $grant) {
            ($this->revoke)($grant, $reason, $revokedBy);
            $revoked++;
        }

        return $revoked;
    }
}
