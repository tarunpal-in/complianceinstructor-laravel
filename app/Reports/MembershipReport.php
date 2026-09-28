<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\BillingPeriod;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Support\Period;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How the recurring side of the business is doing.
 *
 * **MRR is normalised, not summed.** A subscription snapshots the price it was
 * sold at (§5.5), and that price is per *term* — a year's membership at $1,200
 * is $100 a month, not $1,200. Adding the raw prices would make every annual
 * renewal look like a spike and every month without one look like a collapse.
 * BillingPeriod::months() carries the divisor, so there is one definition of
 * how long a term is rather than a second copy here.
 *
 * One-time plans are excluded from MRR entirely. They are revenue, and the
 * sales report counts them; they are not *recurring* revenue, and including
 * them would make the figure meaningless the moment one is sold.
 *
 * **Churn is people, not money.** Cancellations in the period over the members
 * active at the start of it. Revenue churn is a different number that needs a
 * different denominator, and quoting one while calling it the other is how a
 * board deck ends up wrong.
 */
class MembershipReport
{
    public function __construct(private readonly Period $period) {}

    // Now -----------------------------------------------------------------------------

    /**
     * Subscriptions currently being paid for.
     *
     * Cashier's own `active` scope, not a hand-rolled status check — it already
     * accounts for trials and grace periods, and a second definition here would
     * drift from what the Memberships screen shows.
     *
     * @return Builder<Subscription>
     */
    public function activeQuery(): Builder
    {
        return Subscription::query()->active();
    }

    public function activeMembers(): int
    {
        return $this->activeQuery()->count();
    }

    /** Monthly recurring revenue, in cents. */
    public function mrrCents(): int
    {
        return $this->normalisedMonthly($this->activeQuery());
    }

    /** MRR × 12. Stated rather than computed elsewhere, so the two cannot differ. */
    public function arrCents(): int
    {
        return $this->mrrCents() * 12;
    }

    /** What the average member is worth a month, in cents. */
    public function averageMrrCents(): int
    {
        $members = $this->activeMembers();

        return $members === 0 ? 0 : intdiv($this->mrrCents(), $members);
    }

    // Over the period ---------------------------------------------------------------------

    public function started(): int
    {
        return Subscription::query()
            ->whereBetween('created_at', [$this->period->from, $this->period->to])
            ->count();
    }

    public function cancelled(): int
    {
        return Subscription::query()
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$this->period->from, $this->period->to])
            ->count();
    }

    /**
     * Cancellations over the members there were to lose, as a percentage.
     *
     * Null rather than zero when there was nobody at the start: "0% churn" on a
     * month that began with no members reads as a triumph rather than as an
     * absence of data.
     */
    public function churnPercent(): ?float
    {
        $atStart = $this->activeAt($this->period->from);

        if ($atStart === 0) {
            return null;
        }

        return round(($this->cancelled() / $atStart) * 100, 1);
    }

    /**
     * Members who were active on a given date.
     *
     * Worked out from the term dates rather than from the current status,
     * because status is a fact about now and this is a question about then.
     */
    public function activeAt(DateTimeInterface $moment): int
    {
        return Subscription::query()
            ->where('created_at', '<=', $moment)
            ->where(fn (Builder $query) => $query
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', $moment))
            ->count();
    }

    // Breakdowns --------------------------------------------------------------------------

    /**
     * MRR by plan, so the shape of the revenue is visible.
     *
     * @return Collection<int, ReportRow>
     */
    public function mrrByPlan(): Collection
    {
        return MembershipPlan::query()
            ->with('subscriptions')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (MembershipPlan $plan): ReportRow => new ReportRow(
                label: $plan->name,
                cents: $this->normalisedMonthly($this->subscriptionsOn($plan)),
                note: trans_choice(
                    ':count member|:count members',
                    $count = $this->subscriptionsOn($plan)->count(),
                    ['count' => $count],
                ),
            ))
            ->filter(fn (ReportRow $row): bool => $row->cents > 0)
            ->values();
    }

    /**
     * Memberships whose term ends soon.
     *
     * The operational half of this report: a renewal is a conversation, and one
     * nobody had is a cancellation that did not need to happen.
     *
     * @return Collection<int, Subscription>
     */
    public function renewalsDue(int $withinDays = 30): Collection
    {
        return Subscription::query()
            ->active()
            ->whereNotNull('term_ends_at')
            ->whereBetween('term_ends_at', [Period::now(), Period::now()->addDays($withinDays)])
            ->with(['member', 'plan'])
            ->orderBy('term_ends_at')
            ->get();
    }

    /**
     * Members who have used most of what they are owed.
     *
     * An upsell list, and the one figure on this screen that is about a person
     * rather than about money. Unlimited allowances are skipped — there is
     * nothing to run out of.
     *
     * @return Collection<int, array{subscription: Subscription, format: string, used: int, remaining: int}>
     */
    public function nearingTheirLimit(float $threshold = 0.8): Collection
    {
        $rows = collect();

        foreach (Subscription::query()->active()->with(['member', 'plan', 'entitlements'])->get() as $subscription) {
            foreach ($subscription->entitlements as $allowance) {
                $quantity = $allowance->quantity;

                if ($quantity === null || $quantity === 0) {
                    continue;
                }

                $used = $subscription->usedInWindow($allowance->format);

                if ($used / $quantity < $threshold) {
                    continue;
                }

                $rows->push([
                    'subscription' => $subscription,
                    'format' => $allowance->format->label(),
                    'used' => $used,
                    'remaining' => max(0, $quantity - $used),
                ]);
            }
        }

        return $rows->sortBy('remaining')->values();
    }

    /**
     * Active subscriptions on one plan, as a query rather than a relation.
     *
     * normalisedMonthly needs a Builder it can clone and add eager loads to,
     * and a HasMany is neither.
     *
     * @return Builder<Subscription>
     */
    private function subscriptionsOn(MembershipPlan $plan): Builder
    {
        return Subscription::query()->where('membership_plan_id', $plan->getKey())->active();
    }

    /**
     * A term's price expressed as a month, summed.
     *
     * Done in PHP rather than SQL because the divisor comes from the plan's
     * billing period and the expression for it would differ between MySQL and
     * SQLite — a poor trade for a few hundred rows.
     *
     * @param  Builder<Subscription>  $query
     */
    private function normalisedMonthly(Builder $query): int
    {
        $total = 0;

        foreach ($query->clone()->with('plan')->get() as $subscription) {
            $period = $subscription->plan?->billing_period;

            // No plan, or a one-off purchase: revenue, but not recurring.
            if (! $period instanceof BillingPeriod || ! $period->isRecurring()) {
                continue;
            }

            $months = $period->months() ?? 1;

            /*
             * The price it was sold at, falling back to the plan's only where
             * the snapshot is missing — members keep what they signed up for,
             * and a plan repriced since must not silently restate last year's
             * MRR. The plan is known to exist by this point, or the check
             * above would have skipped this row.
             */
            $price = (int) $subscription->price_cents;

            if ($price === 0) {
                $price = (int) $subscription->plan->price_cents;
            }

            $total += intdiv($price, max(1, $months));
        }

        return $total;
    }
}
