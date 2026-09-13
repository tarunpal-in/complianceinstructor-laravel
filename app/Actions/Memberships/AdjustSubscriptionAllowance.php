<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Enums\AllowanceWindow;
use App\Enums\Format;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Change what one format grants for one member, leaving the rest alone.
 *
 * The counterpart to "re-apply plan terms", which replaces every allowance at
 * once from the plan. That is the right tool when a membership was sold under
 * the wrong plan; it is far too blunt for "give this person one more live
 * session", which is what support actually asks for.
 *
 * **This changes the cap, permanently, for the life of the membership**
 * (agreed 2026-09-13) — including next month on a per-month window. It is a
 * change to what somebody is entitled to. Giving back a single session they
 * could not attend is a different act with a different tool: revoke the grant
 * that spent it, and the count returns on its own.
 *
 * Both facts are said plainly in the confirmation, because choosing the wrong
 * one of the two is the mistake this pair invites.
 */
class AdjustSubscriptionAllowance
{
    /**
     * @param  int|null  $quantity  null grants it without limit (§5.5)
     */
    public function __invoke(
        Subscription $subscription,
        Format $format,
        ?int $quantity,
        string $reason,
    ): SubscriptionEntitlement {
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason — somebody will ask why this member differs from their plan.');
        }

        if ($quantity !== null && $quantity < 0) {
            throw new RuntimeException('An allowance cannot be negative. Use zero to withdraw the format, or leave it unlimited.');
        }

        return DB::transaction(function () use ($subscription, $format, $quantity, $reason): SubscriptionEntitlement {
            $locked = Subscription::query()
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->load('entitlements');

            $existing = $locked->allowanceFor($format);

            /*
             * A format the member was never sold can be added here, and the
             * window has to come from somewhere. The plan's own row for that
             * format is the honest answer — it is what the business decided
             * this format resets on — falling back to the term.
             */
            // `->` rather than `?->` throughout: null coalescing already covers
            // each missing step, and `?->` before `??` is redundant.
            $window = $existing->window
                ?? $locked->plan?->entitlements->firstWhere('format', $format)->window
                ?? AllowanceWindow::Term;

            $row = SubscriptionEntitlement::query()->updateOrCreate(
                ['subscription_id' => $locked->getKey(), 'format' => $format],
                ['quantity' => $quantity, 'window' => $window],
            );

            activity('subscription_allowance')
                ->performedOn($row)
                ->causedBy(auth()->user())
                ->withProperties([
                    'subscription_id' => $locked->getKey(),
                    'format' => $format->value,
                    'from' => $existing === null ? 'not included' : ($existing->quantity ?? 'unlimited'),
                    'to' => $quantity ?? 'unlimited',
                    'reason' => trim($reason),
                ])
                ->log('Allowance adjusted');

            return $row;
        });
    }
}
