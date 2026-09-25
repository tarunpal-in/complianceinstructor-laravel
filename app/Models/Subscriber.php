<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use Carbon\CarbonInterface;
use Database\Factories\SubscriberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One address on the mailing list — §5.7.
 *
 * Note the absence of SoftDeletes, which almost every other content-bearing
 * model here uses. It is deliberate: this table is a suppression list, and a
 * soft-deleted row still frees nothing while making "why is this person being
 * emailed again" much harder to answer. See the migration.
 *
 * @property string $email The accessor below defines a mutator of the same name, which hides the column from static analysis unless it is declared here.
 * @property SubscriberStatus $status
 * @property SubscriberSource $source
 * @property CarbonInterface|null $subscribed_at
 * @property CarbonInterface|null $unsubscribed_at
 * @property CarbonInterface|null $confirmed_at
 */
class Subscriber extends Model
{
    /** @use HasFactory<SubscriberFactory> */
    use HasFactory, LogsActivity;

    /**
     * How long a confirmation link lasts.
     *
     * Long enough to survive a weekend and a holiday, short enough that a link
     * found in an old inbox does not silently add somebody a year later.
     */
    public const CONFIRMATION_LIFETIME_DAYS = 14;

    protected $fillable = [
        'email', 'name', 'status', 'source',
        'subscribed_at', 'confirmed_at', 'consent_ip', 'consent_user_agent',
        'unsubscribed_at', 'unsubscribe_reason', 'bounced_at', 'bounce_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriberStatus::class,
            'source' => SubscriberSource::class,
            'subscribed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'bounced_at' => 'datetime',
        ];
    }

    /**
     * The email is the identity, so it is normalised at the boundary.
     *
     * More than a convenience here: the UNIQUE index is what stops one person
     * getting two copies of every campaign, and it cannot do that if
     * "Dana@Example.com" and "dana@example.com" are two rows.
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
     * §9 — the audit trail that matters most on this table.
     *
     * "We never signed up for this" and "I unsubscribed months ago" are both
     * answered from here, and both are answered badly by a row that only knows
     * its current state.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'source', 'subscribed_at', 'confirmed_at', 'unsubscribed_at', 'unsubscribe_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('subscriber');
    }

    // Relationships -----------------------------------------------------------------

    /**
     * Their account, if the email matches one.
     *
     * Joined on the email rather than a stored key, for the same reason as
     * Inquiry::customer: somebody usually joins the list before they buy, and a
     * column written at signup would be null forever for exactly the people who
     * went on to become customers.
     *
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }

    // Scopes -------------------------------------------------------------------------

    /**
     * Addresses a campaign may go to. The only scope a send should ever use.
     *
     * One place, so a future mailing job cannot express "everyone except
     * unsubscribes" slightly differently from the screen that reports the list
     * size — §14 #9's shape, applied to who gets emailed.
     *
     * @param  Builder<Subscriber>  $query
     */
    public function scopeMailable(Builder $query): void
    {
        $query->where('status', SubscriberStatus::Subscribed);
    }

    /**
     * Addresses held off the list on purpose.
     *
     * @param  Builder<Subscriber>  $query
     */
    public function scopeSuppressed(Builder $query): void
    {
        $query->whereIn('status', [
            SubscriberStatus::Unsubscribed->value,
            SubscriberStatus::Bounced->value,
            SubscriberStatus::Complained->value,
        ]);
    }

    /**
     * Signed up and never confirmed.
     *
     * @param  Builder<Subscriber>  $query
     */
    public function scopeAwaitingConfirmation(Builder $query): void
    {
        $query->where('status', SubscriberStatus::Pending);
    }

    /**
     * The ones a mail provider has rejected or reported.
     *
     * Kept separate from ordinary unsubscribes on the screen, because these are
     * a deliverability problem rather than somebody's choice.
     *
     * @param  Builder<Subscriber>  $query
     */
    public function scopeProblems(Builder $query): void
    {
        $query->whereIn('status', [
            SubscriberStatus::Bounced->value,
            SubscriberStatus::Complained->value,
        ]);
    }

    // State ---------------------------------------------------------------------------

    public function isMailable(): bool
    {
        return $this->status->isMailable();
    }

    public function isSuppressed(): bool
    {
        return $this->status->isSuppressed();
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->status === SubscriberStatus::Pending;
    }

    /** Whether the address was proved to belong to whoever signed up. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** How the consent reads in one line, for a screen and for an export. */
    public function describeConsent(): string
    {
        if ($this->subscribed_at === null) {
            return 'Not recorded';
        }

        $when = $this->subscribed_at->format('j M Y');

        return $this->isConfirmed()
            ? 'Confirmed '.$this->confirmed_at?->format('j M Y')
            : 'Signed up '.$when.', not confirmed';
    }

    // Links -------------------------------------------------------------------------------

    /**
     * The link that goes in the footer of every campaign.
     *
     * Signed and **not** expiring: people read old email, and a required
     * unsubscribe mechanism that has quietly stopped working is worse than not
     * having sent the mail. Signed rather than a stored token because §9 makes
     * signed URLs the rule, and a token column is one more thing that can leak.
     */
    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('mailing-list.unsubscribe', ['subscriber' => $this->getKey()]);
    }

    /** The double opt-in link. Expires, unlike the one above. */
    public function confirmUrl(): string
    {
        return URL::temporarySignedRoute(
            'mailing-list.confirm',
            now()->addDays(self::CONFIRMATION_LIFETIME_DAYS),
            ['subscriber' => $this->getKey()],
        );
    }

    // Settings ---------------------------------------------------------------------------

    /**
     * Whether a new signup has to prove the mailbox is theirs.
     *
     * Off by default, because there is no transactional mail wired up yet and
     * turning it on now would mean every signup sitting unconfirmed forever,
     * waiting for an email nobody can send. The state machine is built for it
     * regardless, so P8 turns this on and nothing else changes.
     *
     * On is the right answer once mail exists: it is what stops a typo'd or
     * malicious address joining the list, and it is what mail providers expect
     * from a sender they have not seen before.
     */
    public static function requiresDoubleOptIn(): bool
    {
        return (bool) Setting::get('mailing_list.double_opt_in', false);
    }
}
