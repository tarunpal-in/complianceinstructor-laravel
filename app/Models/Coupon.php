<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CouponType;
use App\Enums\Format;
use App\Support\Money;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property string $code
 * @property int|null $percent_off
 * @property int|null $amount_off_cents
 * @property Format|null $scope_format
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property \Illuminate\Database\Eloquent\Collection<int, CouponRedemption> $redemptions
 *
 * CLAUDE.md §5.4 and §8 improvement #2.
 *
 * Nothing here decides whether a coupon may be used — that is ValidateCoupon,
 * which runs server-side inside the order transaction. The methods on this
 * model answer questions *about* a coupon so the admin screen and the validator
 * agree on what "expired" or "exhausted" means.
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'code', 'description', 'percent_off', 'amount_off_cents', 'currency',
        'max_redemptions', 'max_per_user', 'minimum_spend_cents',
        'starts_at', 'ends_at', 'scopeable_type', 'scopeable_id', 'scope_format', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'scope_format' => Format::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Codes are typed by customers in whatever case they like, so they are
        // stored one way and compared that way. §14 #12 — a coupon that works
        // for "SAVE10" and not "save10" is the same class of bug as the
        // predecessor's 'E_transcript' vs 'E_transcripts'.
        static::saving(static function (Coupon $coupon): void {
            $coupon->code = strtoupper(trim($coupon->code));
        });
    }

    /** §9 — the predecessor had no audit trail, and a discount is money. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'code', 'percent_off', 'amount_off_cents', 'max_redemptions',
                'max_per_user', 'minimum_spend_cents', 'starts_at', 'ends_at', 'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('coupon');
    }

    // Relationships ---------------------------------------------------------

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * The industry or webinar this is limited to, if any.
     *
     * @return MorphTo<Model, $this>
     */
    public function scopeable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes ----------------------------------------------------------------

    /**
     * Live right now: switched on, started, and not yet ended.
     *
     * The single definition of the phrase. The admin's tabs and filters reach
     * it through `usableIds()` rather than restating it, so the list can never
     * disagree with what the checkout will accept.
     *
     * @param  Builder<Coupon>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /**
     * The same condition as a subquery, for the admin's builders.
     *
     * Filament hands a filter or a tab an untyped builder, where a model scope
     * is not visible. Selecting ids through a typed query keeps one definition
     * instead of the list growing its own copy that slowly drifts.
     *
     * @return Builder<Coupon>
     */
    public static function usableIds(): Builder
    {
        // Coupon::query() rather than static::query(): the latter is typed
        // Builder<static>, which is not Builder<Coupon> to an analyser that
        // cannot rule out a subclass.
        return Coupon::query()->usable()->select('id');
    }

    /**
     * Find one by the code a customer typed.
     *
     * @param  Builder<Coupon>  $query
     */
    public function scopeMatchingCode(Builder $query, string $code): void
    {
        $query->where('code', strtoupper(trim($code)));
    }

    // The discount ----------------------------------------------------------

    public function type(): CouponType
    {
        return $this->percent_off !== null ? CouponType::Percent : CouponType::Fixed;
    }

    /** "20% off" or "$25.00 off", for a column or a badge. */
    public function describeDiscount(): string
    {
        return $this->type() === CouponType::Percent
            ? $this->percent_off.'% off'
            : Money::format((int) $this->amount_off_cents, $this->currency).' off';
    }

    /**
     * What this takes off a given eligible amount.
     *
     * Never more than the amount itself — a fixed $50 coupon against a $30
     * basket discounts $30, not $50, because a negative total would become a
     * refund the business never agreed to.
     *
     * Integer arithmetic throughout (§4 rule 6): intdiv on the percentage
     * rounds down, so rounding always favours the business by at most a cent
     * rather than drifting either way.
     */
    public function discountFor(int $eligibleCents): int
    {
        if ($eligibleCents < 1) {
            return 0;
        }

        $discount = $this->type() === CouponType::Percent
            ? intdiv($eligibleCents * (int) $this->percent_off, 100)
            : (int) $this->amount_off_cents;

        return min($discount, $eligibleCents);
    }

    // State -----------------------------------------------------------------

    public function hasStarted(): bool
    {
        return $this->starts_at === null || $this->starts_at->isPast();
    }

    public function hasExpired(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /**
     * How many times it has been used.
     *
     * Counted rather than kept in a column: a counter drifts the first time a
     * row is deleted or a transaction rolls back, and a cap that has silently
     * stopped working is worse than no cap.
     */
    public function redemptionCount(): int
    {
        return $this->redemptions()->count();
    }

    public function redemptionCountFor(User $user): int
    {
        return $this->redemptions()->where('user_id', $user->getKey())->count();
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null
            && $this->redemptionCount() >= $this->max_redemptions;
    }

    /** Live right now: active, started, unexpired and with redemptions left. */
    public function isUsable(): bool
    {
        return $this->is_active
            && $this->hasStarted()
            && ! $this->hasExpired()
            && ! $this->isExhausted();
    }

    /** Why it is not usable, for the admin list. */
    public function unusableReason(): ?string
    {
        return match (true) {
            ! $this->is_active => 'Switched off',
            ! $this->hasStarted() => 'Starts '.$this->starts_at?->diffForHumans(),
            $this->hasExpired() => 'Expired '.$this->ends_at?->diffForHumans(),
            $this->isExhausted() => 'All '.$this->max_redemptions.' redemptions used',
            default => null,
        };
    }

    /** How the scope reads — "Human Resources", "Transcript (PDF)", "Everything". */
    public function describeScope(): string
    {
        $parts = [];

        if ($this->scopeable_id !== null) {
            $record = $this->scopeable;

            $parts[] = (string) ($record?->getAttribute('name')
                ?? $record?->getAttribute('title')
                ?? class_basename((string) $this->scopeable_type).' (deleted)');
        }

        if ($this->scope_format !== null) {
            $parts[] = $this->scope_format->label();
        }

        return $parts === [] ? 'Everything' : implode(' · ', $parts);
    }
}
