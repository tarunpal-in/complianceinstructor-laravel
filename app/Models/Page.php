<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublishStatus;
use App\Models\Concerns\ReleasesUniqueNameOnDelete;
use Carbon\CarbonInterface;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A marketing page — CLAUDE.md §5.6.
 *
 * @property PublishStatus $status
 * @property CarbonInterface|null $published_at
 * @property \Illuminate\Database\Eloquent\Collection<int, PageSection> $sections
 */
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, LogsActivity, ReleasesUniqueNameOnDelete, SoftDeletes;

    /**
     * Paths the application already answers on.
     *
     * A page claiming one of these would be unreachable at best — the route
     * that already owns the path wins — and at worst would make the admin panel
     * look broken to whoever took `admin`. Checked when a slug is saved rather
     * than left to be discovered.
     *
     * The auth paths — `login`, `register`, `reset-password`,
     * `two-factor-challenge`, `passkeys` — are held rather than answered. Nothing
     * serves them today (see docs/auth.md), and P5's learner area will want
     * exactly those. Releasing one now so the client could build a page on it,
     * then taking it back, is the reshuffle §13's reserved list exists to avoid.
     *
     * @var array<int, string>
     */
    public const RESERVED_SLUGS = [
        'admin', 'login', 'logout', 'register', 'password', 'forgot-password',
        'reset-password', 'two-factor-challenge', 'passkeys', 'webinars',
        'stripe', 'horizon', 'storage', 'up', 'api', 'livewire', 'filament',
        'preview',
    ];

    /**
     * How long a preview link stays good for.
     *
     * Long enough to send to a client and hear back, short enough that a link
     * forwarded on months later does not still open an unpublished page.
     */
    public const PREVIEW_LIFETIME_DAYS = 7;

    protected $fillable = ['title', 'slug', 'status', 'published_at', 'layout'];

    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /** §9 — the client edits these, and "who changed the pricing copy" gets asked. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'slug', 'status', 'published_at', 'layout'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('page');
    }

    // Relationships -------------------------------------------------------------

    /**
     * The blocks, in the order they are drawn.
     *
     * Ordered here rather than at each call site: a page whose sections came
     * back in a different order between two requests would render differently
     * for no reason anybody could see.
     *
     * @return HasMany<PageSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('sort_order');
    }

    /** @return HasMany<PageSection, $this> */
    public function visibleSections(): HasMany
    {
        return $this->sections()->where('is_visible', true);
    }

    // Scopes ----------------------------------------------------------------------

    /**
     * Pages the public may see.
     *
     * Published *and* past their date. §14 #2 — the predecessor let a request
     * field decide a fulfilment path, and the same shape of mistake here is a
     * page that is live because somebody read the status column and forgot the
     * date.
     *
     * @param  Builder<Page>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', PublishStatus::Published)
            ->where(fn (Builder $dated): Builder => $dated
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now()));
    }

    // State ---------------------------------------------------------------------

    public function isLive(): bool
    {
        return $this->status->isPublic()
            && ($this->published_at === null || $this->published_at->isPast());
    }

    /**
     * What the status column should say, given the date.
     *
     * A page published with a future date is scheduled, whatever anybody set.
     * Derived rather than stored so the list cannot disagree with the date
     * beside it.
     */
    public function derivedStatus(): PublishStatus
    {
        if ($this->status !== PublishStatus::Published) {
            return $this->status;
        }

        return $this->published_at !== null && $this->published_at->isFuture()
            ? PublishStatus::Scheduled
            : PublishStatus::Published;
    }

    /** Its address on the site. */
    public function path(): string
    {
        return '/'.$this->slug;
    }

    /**
     * A link that opens this page before it is published.
     *
     * Signed and expiring rather than guessable, which is the same rule §9
     * applies to files: "signed, expiring URLs on a private disk — never a
     * guessable path". A draft is unreleased marketing copy, and `/about?draft`
     * would hand it to anyone who tried.
     *
     * Deliberately not behind a login. The point is to send it to somebody for
     * review — a client, a compliance officer — and requiring them to have an
     * admin account would mean either no review or an account nobody should
     * have. The signature is the access control, and it runs out.
     */
    public function previewUrl(): string
    {
        return URL::temporarySignedRoute(
            'pages.preview',
            now()->addDays(self::PREVIEW_LIFETIME_DAYS),
            ['page' => $this->getKey()],
        );
    }

    /** Where View should send somebody: the page, or a preview of it. */
    public function viewableUrl(): string
    {
        return $this->isLive() ? $this->path() : $this->previewUrl();
    }

    /**
     * Why this page cannot go live — empty means it can.
     *
     * The same shape as the webinar and plan gates, and for the same reason: a
     * page published with nothing on it is a blank screen with the site's
     * navigation around it, found by a customer rather than by the client.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->visibleSections()->doesntExist()) {
            $problems[] = 'It has no visible content blocks, so it would publish as an empty page.';
        }

        if (in_array($this->slug, self::RESERVED_SLUGS, true)) {
            $problems[] = sprintf('"%s" is a path the site already uses, so this page would never be reachable.', $this->slug);
        }

        return $problems;
    }

    public function isPublishable(): bool
    {
        return $this->problems() === [];
    }

    /** "3 blocks · 1 hidden", for the list. */
    public function describeContent(): string
    {
        $total = $this->sections->count();

        if ($total === 0) {
            return 'Empty';
        }

        $hidden = $this->sections->where('is_visible', false)->count();

        return $total.' '.str('block')->plural($total)
            .($hidden > 0 ? ' · '.$hidden.' hidden' : '');
    }
}
