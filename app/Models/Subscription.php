<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Cashier's subscription, plus the two things §5.5 asks for that Stripe cannot
 * answer: which of our plans this is, and what it actually grants.
 *
 * Extending rather than replacing, because §3 gives Cashier all subscription
 * billing — renewal, proration, dunning and cancellation timing are its job and
 * duplicating any of it would give two answers to "is this person subscribed".
 * Registered through `Cashier::useSubscriptionModel()` in AppServiceProvider so
 * everything Cashier builds internally comes back as this class.
 *
 * @property int|null $price_cents
 * @property-read User|null $member
 * @property \Illuminate\Database\Eloquent\Collection<int, SubscriptionEntitlement> $entitlements
 */
class Subscription extends CashierSubscription
{
    /**
     * Cashier points `newFactory()` at its own, which builds
     * `Laravel\Cashier\Subscription` records — instances with no plan and no
     * entitlements, failing the moment anything reads either.
     */
    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    /** @return BelongsTo<MembershipPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /**
     * The person paying, typed.
     *
     * Cashier's own `owner()` resolves its billable class at runtime, so nothing
     * static can say what it returns. Nullable because Cashier's migration
     * declares `user_id` without a foreign key, so the row can outlive the user.
     *
     * @return BelongsTo<User, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The allowances this subscriber holds — their copy, not the plan's.
     *
     * @return HasMany<SubscriptionEntitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(SubscriptionEntitlement::class);
    }

    /** What they pay, or the plan's price where nothing was snapshotted. */
    public function priceCents(): ?int
    {
        return $this->price_cents ?? $this->plan?->price_cents;
    }

    public function describePrice(): string
    {
        $cents = $this->priceCents();

        return $cents === null ? '—' : Money::format($cents);
    }

    /**
     * "4 live per term · unlimited transcripts" for this subscriber.
     *
     * Deliberately reads the snapshot. A plan whose terms were tightened last
     * month must still show this member what they are owed, not what a new
     * signup would get.
     */
    public function describeAllowances(): string
    {
        if ($this->entitlements->isEmpty()) {
            return 'No allowances recorded';
        }

        return $this->entitlements
            ->map(fn (SubscriptionEntitlement $entitlement): string => $entitlement->describe())
            ->join(' · ');
    }

    /**
     * Whether this subscriber's terms still match the plan they are on.
     *
     * The point of snapshotting is that these two *may* differ; the point of
     * saying so on screen is that support should not have to guess why a member
     * sees something the plan page does not offer.
     */
    public function termsDifferFromPlan(): bool
    {
        $plan = $this->plan;

        if ($plan === null) {
            return false;
        }

        return $this->allowanceFingerprint($this->entitlements->all())
            !== $this->allowanceFingerprint($plan->entitlements->all());
    }

    /**
     * A comparable form of a set of allowance rows.
     *
     * Sorted by format so row order — which is an artefact of insert order and
     * nothing else — never reads as a difference in terms.
     *
     * @param  array<int, PlanEntitlement|SubscriptionEntitlement>  $entitlements
     * @return array<string, string>
     */
    private function allowanceFingerprint(array $entitlements): array
    {
        $fingerprint = [];

        foreach ($entitlements as $entitlement) {
            $fingerprint[$entitlement->format->value] = ($entitlement->quantity ?? 'unlimited')
                .':'.$entitlement->window->value;
        }

        ksort($fingerprint);

        return $fingerprint;
    }
}
