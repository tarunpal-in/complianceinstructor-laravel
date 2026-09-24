<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use Carbon\CarbonInterface;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Somebody who showed interest without buying anything — §5.7.
 *
 * @property string $email The accessor below defines a mutator of the same name, which hides the column from static analysis unless it is declared here.
 * @property LeadStatus $status
 * @property LeadSource $source
 * @property CarbonInterface|null $contacted_at
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'company', 'job_title', 'phone', 'industry_id',
        'source', 'source_detail', 'status', 'assigned_to', 'contacted_at',
        'message', 'note', 'marketing_opt_in',
        'utm_source', 'utm_medium', 'utm_campaign', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'source' => LeadSource::class,
            'contacted_at' => 'datetime',
            'marketing_opt_in' => 'boolean',
        ];
    }

    /**
     * The email decides identity here, so it is normalised at the boundary
     * rather than at each call site.
     *
     * A mutator rather than a job for the intake action, because Filament
     * writes this row directly when staff add a lead by hand — and a register
     * where "Dana@Example.com" and "dana@example.com" are two different people
     * cannot count repeat enquiries or find their account.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::lower(trim($value)),
        );
    }

    /**
     * §9 — who changed a lead's status, and who it was handed to. Both get
     * asked when a deal is argued over.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'assigned_to', 'contacted_at', 'note', 'marketing_opt_in'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('lead');
    }

    // Relationships ----------------------------------------------------------------

    /** @return BelongsTo<Industry, $this> */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Their account, if the email matches one.
     *
     * Joined on the email rather than on a stored `user_id`, because a lead
     * usually arrives before the account does — somebody takes a quiz in March
     * and buys in June. A column written at capture time would be null forever
     * for exactly the people who matter most.
     *
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }

    /**
     * Every enquiry from this address, this one included.
     *
     * Email is deliberately not unique — the same person filling in two forms
     * three months apart is two pieces of evidence about two campaigns. This is
     * what lets the register say "third time" without a query per row.
     *
     * @return HasMany<Lead, $this>
     */
    public function enquiries(): HasMany
    {
        return $this->hasMany(self::class, 'email', 'email');
    }

    // Scopes ------------------------------------------------------------------------

    /**
     * Leads somebody still has to do something about.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [LeadStatus::New->value, LeadStatus::Working->value]);
    }

    /**
     * Came in and was never picked up.
     *
     * The one query that matters commercially: a lead nobody has touched is the
     * marketing spend that produced it going to waste quietly.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeUntouched(Builder $query): void
    {
        $query->where('status', LeadStatus::New)
            ->whereNull('contacted_at')
            ->whereNull('assigned_to');
    }

    /**
     * Leads whose email belongs to an account that has bought something.
     *
     * Derived every time it is asked, never stored. See the table comment.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeConverted(Builder $query): void
    {
        $query->whereHas('customer.orders', self::onlySettled(...));
    }

    /**
     * Accounts that have paid for something.
     *
     * One definition of "bought", reused by the scope above, by the record's
     * own check, and by the register's subselect. Three copies of
     * `whereIn('status', [...])` is how two screens end up disagreeing about
     * whether a partially refunded order counts.
     *
     * @param  Builder<User>  $query
     */
    public static function constrainToBuyers(Builder $query): void
    {
        $query->whereHas('orders', self::onlySettled(...));
    }

    /**
     * Named rather than inline: whereHas hands over an untyped builder, where
     * Order's own scope is invisible to static analysis.
     *
     * @param  Builder<Order>  $query
     */
    private static function onlySettled(Builder $query): void
    {
        $query->settled();
    }

    // What became of them -----------------------------------------------------------

    /**
     * Whether this email belongs to an account.
     *
     * The register adds both of these as subselects (see
     * LeadResource::getEloquentQuery), so a list of fifty leads costs two extra
     * subqueries rather than a hundred. A record opened on its own has no such
     * attribute and asks directly — which is why the fallback is here and not
     * a second copy of the rule in a column closure.
     */
    public function hasAccount(): bool
    {
        if ($this->hasAttribute('has_account')) {
            return (bool) $this->getAttribute('has_account');
        }

        return $this->customer()->exists();
    }

    /** Whether this email has ever paid for anything. */
    public function hasBought(): bool
    {
        if ($this->hasAttribute('has_bought')) {
            return (bool) $this->getAttribute('has_bought');
        }

        return $this->customer()->whereHas('orders', self::onlySettled(...))->exists();
    }

    /** What happened to them, in two words. */
    public function describeOutcome(): string
    {
        return match (true) {
            $this->hasBought() => 'Bought',
            $this->hasAccount() => 'Registered',
            default => 'Not yet',
        };
    }

    public function outcomeColor(): string
    {
        return match (true) {
            $this->hasBought() => 'success',
            $this->hasAccount() => 'info',
            default => 'gray',
        };
    }

    // State ---------------------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** How long a new lead has been sitting there. */
    public function ageInDays(): int
    {
        return (int) ($this->created_at?->diffInDays(now()) ?? 0);
    }

    /**
     * Who they are, in one line, for a list that has to stay scannable.
     *
     * Job title and company rather than the email, because that is what tells
     * somebody whether this lead is worth an hour.
     */
    public function describeRole(): ?string
    {
        $parts = array_filter([$this->job_title, $this->company]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
