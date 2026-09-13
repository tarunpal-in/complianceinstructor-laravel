<?php

declare(strict_types=1);

namespace App\Actions\Entitlements;

use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use Carbon\CarbonInterface;

/**
 * Write one row into the access ledger — CLAUDE.md §5.2.
 *
 * Everything that grants access comes through here: a captured order, a spent
 * membership allowance, a staff comp. One way in means one shape of row, and
 * §14 #9's four divergent copies of the access rule cannot happen again.
 *
 * Idempotent by the table's UNIQUE (§14 #5). A replayed webhook or a
 * double-clicked button finds the existing grant and returns it rather than
 * writing a second — and a grant that had been revoked is restored rather than
 * duplicated, because a person asking for access again is asking for the same
 * access, not for a second copy of it.
 */
class GrantEntitlement
{
    public function __invoke(
        User $user,
        Webinar $webinar,
        Format $format,
        EntitlementSource $source,
        ?int $sourceId = null,
        ?CarbonInterface $expiresAt = null,
        ?string $note = null,
    ): Entitlement {
        $entitlement = Entitlement::query()->firstOrNew([
            'user_id' => $user->getKey(),
            'webinar_id' => $webinar->getKey(),
            'format' => $format,
            'source_type' => $source,
            'source_id' => $sourceId,
        ]);

        $entitlement->fill([
            'granted_at' => $entitlement->granted_at ?? now(),
            'expires_at' => $expiresAt,
            // Restored rather than left revoked: this is the same grant coming
            // back, and a revoked row that silently stayed revoked would be a
            // customer told they have access and then refused it.
            'revoked_at' => null,
            'revoked_by' => null,
            'note' => $note ?? $entitlement->note,
        ])->save();

        return $entitlement;
    }
}
