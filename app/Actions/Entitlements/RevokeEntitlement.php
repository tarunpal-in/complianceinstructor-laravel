<?php

declare(strict_types=1);

namespace App\Actions\Entitlements;

use App\Models\Entitlement;
use RuntimeException;

/**
 * Take one grant back — CLAUDE.md §5.2.
 *
 * "A refund revokes access by writing `revoked_at` rather than unwinding four
 * related records." The same single write serves the support case this was
 * built for: a member spent a live session, could not attend, and wants the
 * allowance back. Revoking the grant returns it, because the quota was never a
 * stored number — it is counted from the rows that are still standing.
 *
 * The reason is required, not optional. Access disappearing from somebody's
 * dashboard is the kind of thing that generates a second support ticket months
 * later, and "because" needs to be on the record rather than in a memory.
 */
class RevokeEntitlement
{
    public function __invoke(Entitlement $entitlement, string $reason, ?int $revokedBy = null): Entitlement
    {
        if ($entitlement->isRevoked()) {
            // Loudly, rather than silently doing nothing: a second revoke is
            // usually a double-click, but it can be two people acting on the
            // same ticket, and the second should learn that.
            throw new RuntimeException('That access has already been given back.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason — it is what the next person reads.');
        }

        $entitlement->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $revokedBy ?? auth()->id(),
            'note' => trim($reason),
        ])->save();

        return $entitlement;
    }
}
