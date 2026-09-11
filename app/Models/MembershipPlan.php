<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\Format;
use App\Models\Concerns\ReleasesUniqueNameOnDelete;
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
 * @property array<array-key, mixed>|null $highlights
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
    use HasFactory, LogsActivity, ReleasesUniqueNameOnDelete, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'highlights', 'price_cents', 'currency', 'billing_period',
        'duration_months', 'stripe_price_id', 'is_active', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'billing_period' => BillingPeriod::class,
            'highlights' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * A deleted plan gives its Stripe price back.
     *
     * The ID cannot be renamed the way a slug can — it has to match Stripe
     * exactly — and a deleted plan cannot be sold, so holding it only blocks a
     * new plan from using that price. See the trait for the full reasoning.
     *
     * @return array<int, string>
     */
    protected function namesClearedOnDelete(): array
    {
        return ['stripe_price_id'];
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
     * The price as a pricing card shows it — "$299", whole dollars.
     *
     * The comp has no cents on it, and a card reading "$299.00" is a card that
     * has been generated rather than designed. Nothing is lost: the exact
     * amount is what checkout charges, from `price_cents`.
     */
    public function cardPrice(): string
    {
        return '$'.number_format(intdiv($this->price_cents, 100));
    }

    /**
     * What the price buys — "3 months", "month".
     *
     * A one-off says its span, because that is the only place the span is
     * recorded. A recurring plan says its period, because it renews.
     */
    public function describeTerm(): string
    {
        if ($this->duration_months !== null) {
            return $this->duration_months.' '.str('month')->plural($this->duration_months)->toString();
        }

        return $this->billing_period->noun();
    }

    /**
     * The quota badge — "3 Live Webinars".
     *
     * Derived from the entitlements rather than typed, because it is the one
     * line on the card the business is actually promising. The live allowance
     * leads where there is one; otherwise the first row does.
     */
    public function describeHeadline(): ?string
    {
        $entitlement = $this->entitlements->firstWhere('format', Format::Live)
            ?? $this->entitlements->first();

        return $entitlement?->headline();
    }

    /**
     * The tick-and-cross list, as the card needs it.
     *
     * Read defensively: `highlights` is a JSON column, so a hand-edited or
     * half-migrated row must render a short card rather than a 500 on the
     * homepage.
     *
     * @return array<int, array{label: string, included: bool}>
     */
    public function highlightRows(): array
    {
        $rows = [];

        foreach ($this->highlights ?? [] as $highlight) {
            if (! is_array($highlight) || ! is_string($highlight['label'] ?? null)) {
                continue;
            }

            $rows[] = [
                'label' => $highlight['label'],
                // Absent means included: a list someone typed without touching
                // the toggles should read as a list of what they get.
                'included' => (bool) ($highlight['included'] ?? true),
            ];
        }

        return $rows;
    }

    /**
     * "4 live per term · unlimited transcripts" — the whole tier in one line.
     *
     * For places with a line to spare: global search results, a tooltip. The
     * admin list uses allowanceLines() instead, because four of these joined
     * end to end is far wider than a table column.
     */
    public function describeAllowances(): string
    {
        if ($this->entitlements->isEmpty()) {
            return 'Grants nothing yet';
        }

        return implode(' · ', $this->allowanceLines());
    }

    /**
     * The same allowances, one per entry.
     *
     * A table cell given the joined string has to wrap it, and with a column
     * this narrow it wraps at nearly every word — three allowances became a
     * dozen lines and pushed the whole table into a horizontal scroll. Handing
     * the column a list lets it break where the meaning breaks, and show only
     * the first couple.
     *
     * Sorted by format rather than by however the rows came back. Nothing
     * orders `plan_entitlements`, so the database is free to return them in any
     * order it likes — a plan whose allowances reshuffled between two page
     * loads would read as a plan that had changed. Format order also happens to
     * be the order a customer cares about: live first, then what they keep.
     *
     * @return array<int, string>
     */
    public function allowanceLines(): array
    {
        $order = array_flip(Format::values());

        return $this->entitlements
            ->sortBy(fn (PlanEntitlement $entitlement): int => $order[$entitlement->format->value] ?? PHP_INT_MAX)
            ->map(fn (PlanEntitlement $entitlement): string => $entitlement->describe())
            ->values()
            ->all();
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
