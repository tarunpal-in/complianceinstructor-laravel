<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntitlementSource;
use App\Enums\Format;
use Carbon\CarbonInterface;
use Database\Factories\EntitlementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One grant of access — CLAUDE.md §5.2.
 *
 * @property Format $format
 * @property EntitlementSource $source_type
 * @property CarbonInterface $granted_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $revoked_at
 */
class Entitlement extends Model
{
    /** @use HasFactory<EntitlementFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id', 'webinar_id', 'format', 'source_type', 'source_id',
        'granted_at', 'expires_at', 'revoked_at', 'revoked_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'format' => Format::class,
            'source_type' => EntitlementSource::class,
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** §9 — access granted and taken away is exactly what an audit trail is for. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'webinar_id', 'format', 'source_type', 'source_id', 'expires_at', 'revoked_at', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('entitlement');
    }

    // The only access question anyone asks -------------------------------------

    /**
     * Whether this person may have this webinar in this format.
     *
     * §5.2: "One helper is the **only** way any part of the app asks about
     * access. No controller ever queries orders or subscriptions to decide what
     * someone may see." §14 #9 bans re-deriving it anywhere else — the
     * predecessor duplicated the logic four times and got it wrong in one of
     * them, so package buyers could not watch what they had bought.
     */
    public static function grants(User $user, Webinar $webinar, Format $format): bool
    {
        return self::query()
            ->where('user_id', $user->getKey())
            ->where('webinar_id', $webinar->getKey())
            ->where('format', $format)
            ->active()
            ->exists();
    }

    // Scopes --------------------------------------------------------------------

    /**
     * Grants that actually give access right now.
     *
     * @param  Builder<Entitlement>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $live): Builder => $live
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    /**
     * Grants that still count against a quota.
     *
     * Deliberately different from `active`. A membership allowance is spent the
     * moment it is used, and an expired grant does not hand it back — the window
     * it was spent in has moved on anyway. Only revoking returns an allowance,
     * which is the whole point of revoking being a separate act.
     *
     * @param  Builder<Entitlement>  $query
     */
    public function scopeNotRevoked(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * Everything one order, subscription or staff member granted.
     *
     * @param  Builder<Entitlement>  $query
     */
    public function scopeFromSource(Builder $query, EntitlementSource $source, ?int $sourceId = null): void
    {
        $query->where('source_type', $source);

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }
    }

    // Relationships -------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Webinar, $this> */
    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** The membership that paid for this, where one did. */
    public function subscription(): ?Subscription
    {
        if ($this->source_type !== EntitlementSource::Subscription || $this->source_id === null) {
            return null;
        }

        return Subscription::query()->find($this->source_id);
    }

    // State ---------------------------------------------------------------------

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->hasExpired();
    }

    /** "Active", "Given back", "Expired" — what the admin column shows. */
    public function describeState(): string
    {
        return match (true) {
            $this->isRevoked() => 'Given back',
            $this->hasExpired() => 'Expired',
            default => 'Active',
        };
    }

    public function stateColor(): string
    {
        return match (true) {
            $this->isRevoked() => 'warning',
            $this->hasExpired() => 'gray',
            default => 'success',
        };
    }
}
