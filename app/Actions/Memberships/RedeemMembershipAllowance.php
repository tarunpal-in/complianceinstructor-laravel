<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Actions\Entitlements\GrantEntitlement;
use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Spend one of a member's allowances on a webinar — CLAUDE.md §4 rule 2, and
 * the third of §5.2's three routes to access.
 *
 * The quota check and the grant happen under one lock, in one transaction. §14
 * #4 has the predecessor writing six tables in sequence with no transaction
 * after taking money; the same shape of bug here would let two tabs each see
 * "1 left" and both spend it. Locking the subscription is what makes the count
 * and the write one decision instead of two.
 *
 * The predecessor never reset an allowance at all — it summed all-time
 * purchases — so the window the count runs over is doing real work here, not
 * decoration.
 */
class RedeemMembershipAllowance
{
    public function __construct(private readonly GrantEntitlement $grant) {}

    public function __invoke(Subscription $subscription, Webinar $webinar, Format $format): Entitlement
    {
        return DB::transaction(function () use ($subscription, $webinar, $format): Entitlement {
            $locked = Subscription::query()
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->load('entitlements');

            $member = $locked->member;

            if ($member === null) {
                throw new RuntimeException('That membership has no account attached to it.');
            }

            if (! $locked->state()->grantsAccess()) {
                throw new RuntimeException('That membership is '.strtolower($locked->state()->label()).', so it cannot be spent.');
            }

            $allowance = $locked->allowanceFor($format);

            if ($allowance === null) {
                throw new RuntimeException('That membership does not include '.strtolower($format->label()).'.');
            }

            // Unlimited passes without counting. NULL is not a quantity (§5.5),
            // so nothing here does arithmetic on it.
            if (! $allowance->isUnlimited() && $locked->usedInWindow($format) >= (int) $allowance->quantity) {
                throw new RuntimeException(sprintf(
                    'They have used all %d %s %s.',
                    $allowance->quantity,
                    strtolower($format->pluralLabel()),
                    $allowance->window->shortLabel(),
                ));
            }

            /*
             * Access lasts as long as the membership that paid for it. Without
             * this a member could spend an allowance on the last day of a
             * twelve-month pass and keep the recording for ever, which is not
             * what a term-limited membership sells.
             */
            return ($this->grant)(
                $member,
                $webinar,
                $format,
                EntitlementSource::Subscription,
                $locked->getKey(),
                $locked->accessEndsAt(),
                'Spent from their '.($locked->plan->name ?? 'membership').'.',
            );
        });
    }
}
