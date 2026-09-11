<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Support\Money;
use Database\Factories\MembershipPlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property BillingPeriod $billing_period
 * @property \Illuminate\Database\Eloquent\Collection<int, PlanEntitlement> $entitlements
 *
 * CLAUDE.md §5.5 — a membership tier.
 *
 * Its entitlement rows are a **template**, not a live contract. A subscriber
 * gets a copy at signup (see `subscription_entitlements`), so editing a plan
 * changes what new members get and never what an existing one already paid for.
 */
class MembershipPlan extends Model
{
    /** @use HasFactory<MembershipPlanFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'price_cents', 'currency', 'billing_period',
        'duration_months', 'stripe_price_id', 'is_active', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'billing_period' => BillingPeriod::class,
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /** §9 — a plan is a price, and a price change is worth being able to trace. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'price_cents', 'billing_period', 'duration_months',
                'stripe_price_id', 'is_active', 'is_featured',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('membership_plan');
    }

    // Relationships ---------------------------------------------------------

    /** @return HasMany<PlanEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // Scopes ----------------------------------------------------------------

    /** @param  Builder<MembershipPlan>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order');
    }

    // Presentation ----------------------------------------------------------

    /** "$99.00 / month". */
    public function describePrice(): string
    {
        return Money::format($this->price_cents, $this->currency).' '.$this->billing_period->suffix();
    }

    /**
     * "4 live per term · unlimited transcripts", for the plan list.
     *
     * The whole value of a tier in one line — which is what the client compares
     * plans by, and what §5.5's 17 replaced columns could never show at a glance.
     */
    public function describeAllowances(): string
    {
        if ($this->entitlements->isEmpty()) {
            return 'Grants nothing yet';
        }

        return $this->entitlements
            ->map(fn (PlanEntitlement $entitlement): string => $entitlement->describe())
            ->join(' · ');
    }

    // Publishing --------------------------------------------------------------

    /**
     * Why this plan cannot be sold yet — empty means it can.
     *
     * Same shape as the webinar publish gate, and for the same reason: an
     * unsellable plan that renders on the pricing page fails at the point a
     * customer tries to pay, which is the worst possible moment to find out.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->entitlements->isEmpty()) {
            $problems[] = 'It grants nothing, so a subscriber would pay for no access.';
        }

        if ($this->price_cents < 1) {
            $problems[] = 'It has no price.';
        }

        // §3 gives Cashier all subscription billing, so a recurring plan with
        // no Stripe price cannot actually be charged.
        if ($this->billing_period->isRecurring() && blank($this->stripe_price_id)) {
            $problems[] = 'A recurring plan needs a Stripe price ID before anyone can subscribe.';
        }

        // A one-off with no span grants access forever, which is almost never
        // what was meant and is expensive to discover later.
        if (! $this->billing_period->isRecurring() && $this->duration_months === null) {
            $problems[] = 'A one-off plan needs a duration, or access never ends.';
        }

        // The two fields would be telling the customer different things.
        if ($this->billing_period->isRecurring() && $this->duration_months !== null) {
            $problems[] = 'A recurring plan renews on its billing period, so it must not also set a duration.';
        }

        return $problems;
    }

    public function isSellable(): bool
    {
        return $this->problems() === [];
    }

    /** How many people are on this plan right now. */
    public function activeSubscriberCount(): int
    {
        return $this->subscriptions()->active()->count();
    }

    /**
     * Why this plan must not be deleted, or null if it may be.
     *
     * Their allowances would survive — those are snapshotted on the
     * subscription — but the plan is what every screen names the thing they are
     * paying for, and a soft-deleted one reads as "no plan" everywhere. Taking
     * it off the pricing page is what was actually meant.
     */
    public function deletionBlockReason(): ?string
    {
        $members = $this->activeSubscriberCount();

        if ($members === 0) {
            return null;
        }

        return $members.' active '.str('subscription')->plural($members)->toString()
            .' on this plan. Switch "On the pricing page" off instead — that stops new signups without affecting anyone already paying.';
    }
}
