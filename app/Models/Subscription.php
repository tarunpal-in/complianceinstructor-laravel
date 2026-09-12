<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Format;
use App\Enums\SubscriptionState;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 * @property CarbonInterface|null $term_starts_at
 * @property CarbonInterface|null $term_ends_at
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

    /**
     * Only the two columns we add.
     *
     * Cashier declares `ends_at`, `trial_ends_at` and `quantity` on the `$casts`
     * property, and getCasts() merges the property with this method — so
     * restating them here would be duplication, and forgetting one would leave
     * Cashier comparing a string to a date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term_starts_at' => 'datetime',
            'term_ends_at' => 'datetime',
        ];
    }

    // The term ----------------------------------------------------------------

    /** When this membership runs from. Falls back to when the row was made. */
    public function termStartsAt(): ?CarbonInterface
    {
        return $this->term_starts_at ?? $this->created_at;
    }

    /**
     * When access stops, whichever reason comes first.
     *
     * A fixed-term membership ends when its term does; a cancelled one ends
     * when Cashier says. Both can be set — somebody cancels a twelve-month pass
     * in month three — and the earlier one is the one the member experiences.
     */
    public function accessEndsAt(): ?CarbonInterface
    {
        $dates = array_filter([$this->term_ends_at, $this->ends_at]);

        return $dates === [] ? null : min($dates);
    }

    /** Whether a fixed term has run out. */
    public function termHasExpired(): bool
    {
        return $this->term_ends_at !== null && $this->term_ends_at->isPast();
    }

    /**
     * "12 Sep 2026 — 12 Dec 2026", or "12 Sep 2026 — renews".
     *
     * The question the client asked of this screen, in one column: when it
     * started and when it finishes.
     */
    public function describeTermDates(): string
    {
        $starts = $this->termStartsAt()?->format('j M Y') ?? '—';
        $ends = $this->accessEndsAt();

        return $starts.' — '.($ends === null ? 'renews' : $ends->format('j M Y'));
    }

    /** "until 3 Oct 2026", or nothing for a membership that is simply renewing. */
    public function describeAccessEnd(): ?string
    {
        $endsAt = $this->accessEndsAt();

        return $endsAt === null ? null : 'until '.$endsAt->format('j M Y');
    }

    /**
     * How much of a fixed term is left, for a membership that has one.
     *
     * Null for a recurring plan, which has no end to count towards.
     */
    public function daysRemaining(): ?int
    {
        $endsAt = $this->accessEndsAt();

        if ($endsAt === null) {
            return null;
        }

        return max(0, (int) now()->diffInDays($endsAt, absolute: false));
    }

    // State -----------------------------------------------------------------

    /**
     * Where this subscription stands, in the client's terms.
     *
     * Every branch asks Cashier rather than reading `stripe_status` directly,
     * so the admin cannot come to a different conclusion from the billing side.
     * Order matters: a cancelled subscription is still inside its grace period
     * before it is over, and a trial that has been cancelled is ending rather
     * than trialling.
     */
    public function state(): SubscriptionState
    {
        return match (true) {
            // A fixed term that has run out is over, whatever Stripe thinks.
            // Stripe never knew about it: a one-off plan is not billed by
            // Cashier, so `stripe_status` sits at whatever it was sold as.
            $this->termHasExpired() => SubscriptionState::Ended,
            $this->ended() => SubscriptionState::Ended,
            $this->onGracePeriod() => SubscriptionState::Ending,
            // A fixed term still running is not "ending" — it is simply what
            // was bought, and every one-off member would otherwise show as on
            // their way out from the day they joined.
            $this->pastDue() => SubscriptionState::PastDue,
            $this->onTrial() => SubscriptionState::Trialing,
            default => SubscriptionState::fromStripeStatus($this->stripe_status),
        };
    }

    /**
     * Filter by a state, using Cashier's own scopes wherever it has one.
     *
     * The list and the billing side therefore agree by construction. `Ending`
     * has no Cashier scope of its own because it is not a Stripe status — it is
     * `onGracePeriod`, which does.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeInState(Builder $query, SubscriptionState $state): void
    {
        // A run-out term ends a membership regardless of its Stripe status, so
        // every state except Ended has to exclude one — otherwise a finished
        // one-off would still be counted among the active.
        if ($state !== SubscriptionState::Ended) {
            $query->where(fn (Builder $live) => $live
                ->whereNull('term_ends_at')
                ->orWhere('term_ends_at', '>', now()));
        }

        match ($state) {
            SubscriptionState::Ended => $query->where(fn (Builder $over) => $over
                ->where('term_ends_at', '<=', now())
                ->orWhere(fn (Builder $cancelled) => $cancelled->ended())),
            SubscriptionState::Ending => $query->onGracePeriod(),
            SubscriptionState::PastDue => $query->pastDue(),
            SubscriptionState::Trialing => $query->onTrial()->notOnGracePeriod(),
            SubscriptionState::Active => $query->active()->notOnGracePeriod()->notOnTrial(),
            SubscriptionState::Unknown => $query->whereNotIn(
                'stripe_status',
                array_column(SubscriptionState::cases(), 'value'),
            ),
            default => $query->where('stripe_status', $state->value)->notOnGracePeriod(),
        };
    }

    /**
     * How long they have been a member, in words.
     *
     * `created_at` rather than anything from Stripe: this runs in a table, and
     * Cashier's period accessors call the Stripe API on every read.
     */
    public function describeTenure(): string
    {
        return $this->created_at?->diffForHumans() ?? '—';
    }

    // Money -------------------------------------------------------------------

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

        return implode(' · ', $this->allowanceLines());
    }

    /**
     * The same allowances, one per entry, for a table column.
     *
     * Sorted by format for the reason MembershipPlan::allowanceLines() is:
     * nothing orders the rows, so a member whose allowances reshuffled between
     * two page loads would read as a member whose terms had changed.
     *
     * @return array<int, string>
     */
    public function allowanceLines(): array
    {
        $order = array_flip(Format::values());

        return $this->entitlements
            ->sortBy(fn (SubscriptionEntitlement $entitlement): int => $order[$entitlement->format->value] ?? PHP_INT_MAX)
            ->map(fn (SubscriptionEntitlement $entitlement): string => $entitlement->describe())
            ->values()
            ->all();
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
     * The same question as termsDifferFromPlan(), asked of the whole table.
     *
     * Support's actual question after a plan is edited is "who is now on old
     * terms", and that cannot be answered a row at a time by a list that
     * paginates. Compared in the database so the filter reads one page rather
     * than every subscription in the system.
     *
     * Terms differ when either side holds a row the other does not match
     * exactly, so both directions are checked — a plan that gained a format and
     * one that lost a format are both differences.
     *
     * @return Builder<Subscription>
     */
    public static function idsWithAlteredTerms(): Builder
    {
        return Subscription::query()
            ->select('subscriptions.id')
            ->whereNotNull('subscriptions.membership_plan_id')
            ->where(function (Builder $query): void {
                $query
                    ->whereExists(fn (QueryBuilder $sub) => self::rowWithNoMatch(
                        $sub,
                        'subscription_entitlements',
                        'plan_entitlements',
                        'subscription_id',
                        'membership_plan_id',
                    ))
                    ->orWhereExists(fn (QueryBuilder $sub) => self::rowWithNoMatch(
                        $sub,
                        'plan_entitlements',
                        'subscription_entitlements',
                        'membership_plan_id',
                        'subscription_id',
                    ));
            });
    }

    /**
     * "A row in $from with nothing matching it in $against".
     *
     * Written once and called both ways round rather than twice with the names
     * swapped, which is the version that eventually only gets half-fixed.
     *
     * The quantity comparison spells out the NULL case: NULL means unlimited
     * (§5.5), `NULL = NULL` is not true in SQL, and MySQL's null-safe operator
     * is not portable to the SQLite the suite runs on. Without it every
     * unlimited allowance would read as a difference.
     */
    private static function rowWithNoMatch(
        QueryBuilder $query,
        string $from,
        string $against,
        string $fromKey,
        string $againstKey,
    ): QueryBuilder {
        return $query
            ->selectRaw('1')
            ->from($from)
            ->whereColumn($from.'.'.$fromKey, 'subscriptions.'.self::ownerColumn($fromKey))
            ->whereNotExists(fn (QueryBuilder $inner) => $inner
                ->selectRaw('1')
                ->from($against)
                ->whereColumn($against.'.'.$againstKey, 'subscriptions.'.self::ownerColumn($againstKey))
                ->whereColumn($against.'.format', $from.'.format')
                ->whereColumn($against.'.window', $from.'.window')
                ->where(fn (QueryBuilder $quantity) => $quantity
                    ->whereColumn($against.'.quantity', $from.'.quantity')
                    ->orWhere(fn (QueryBuilder $nulls) => $nulls
                        ->whereNull($against.'.quantity')
                        ->whereNull($from.'.quantity'))));
    }

    /** Which column on `subscriptions` a child table's foreign key points at. */
    private static function ownerColumn(string $foreignKey): string
    {
        return $foreignKey === 'subscription_id' ? 'id' : 'membership_plan_id';
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
