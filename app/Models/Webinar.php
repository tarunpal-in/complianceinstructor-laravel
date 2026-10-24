<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\ReferencesMedia;
use App\Contracts\Seoable;
use App\Enums\Format;
use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsMediaUsage;
use App\Models\Concerns\ReleasesUniqueNameOnDelete;
use App\Support\CreditHours;
use Database\Factories\WebinarFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property WebinarType $type
 * @property WebinarStatus $status
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property string|null $attendee_instructions
 * @property int|null $speaker_id
 *
 * CLAUDE.md §5.3, amended by docs/webinars-module.md.
 *
 * Note what is absent: no price. §5.3 makes `offerings` "the ONLY place a price
 * is ever read from", so a webinar never carries one — the detail page's
 * purchase panel reads offerings, and checkout recomputes from the same rows.
 */
class Webinar extends Model implements ReferencesMedia, Seoable
{
    /** @use HasFactory<WebinarFactory> */
    use HasFactory, HasSeo, RecordsMediaUsage, ReleasesUniqueNameOnDelete, SoftDeletes;

    protected $fillable = [
        'type', 'status', 'title', 'slug', 'summary',
        'description',
        'attendee_instructions',
        'speaker_id', 'industry_id',
        'scheduled_at', 'timezone', 'duration_minutes', 'seats_total',
        'card_media_id', 'banner_media_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => WebinarType::class,
            'status' => WebinarStatus::class,
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'seats_total' => 'integer',
            'card_media_id' => 'integer',
            'banner_media_id' => 'integer',
        ];
    }

    // Relationships ---------------------------------------------------------

    /** @return BelongsTo<Speaker, $this> */
    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class);
    }

    /** @return BelongsTo<Industry, $this> */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /** @return HasMany<WebinarAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(WebinarAsset::class);
    }

    /**
     * All handouts (the admin manages active and inactive alike); the learner
     * side loads only the active ones.
     *
     * @return HasMany<WebinarHandout, $this>
     */
    public function handouts(): HasMany
    {
        return $this->hasMany(WebinarHandout::class)->orderBy('sort_order');
    }

    /**
     * The ordered content blocks on the detail page — §6. Replaces the fixed
     * overview/highlights/why/who columns; ordered here so every reader gets
     * them in sequence without re-sorting.
     *
     * @return HasMany<WebinarSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(WebinarSection::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Order lines for this webinar — the basis of the "Most Popular" sort.
     *
     * Only settled orders are counted there (Order::scopeSettled), so a cart
     * that was never paid for cannot inflate a webinar's popularity.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The catalogue-card and hero-background images, picked from the media
     * library — docs/media-manager.md §3.1. References rather than per-webinar
     * uploads, so replacing the file in the library updates every webinar that
     * picked it, and the delete guard (via media_usages) knows it is in use.
     *
     * @return BelongsTo<Media, $this>
     */
    public function cardMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'card_media_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function bannerMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'banner_media_id');
    }

    /** @return BelongsToMany<Accreditation, $this> */
    public function accreditations(): BelongsToMany
    {
        // Table stated explicitly: Laravel would derive `accreditation_webinar`
        // alphabetically, but §5.3 names it webinar_accreditation.
        return $this->belongsToMany(Accreditation::class, 'webinar_accreditation')
            ->withPivot('credits')
            ->withTimestamps();
    }

    // Credits ---------------------------------------------------------------

    /**
     * What this webinar is worth, by the standard rule.
     *
     * The client's rule, given 2026-09-14: 30 minutes of content is half a
     * credit, the same for every body. Derived from the duration rather than
     * stored, so a webinar that gets longer or shorter cannot end up asserting
     * credits it no longer earns.
     */
    public function standardCredits(): string
    {
        return CreditHours::forMinutes($this->duration_minutes);
    }

    /**
     * What one body awards for this webinar.
     *
     * The pivot value is an override and is normally null. §12 warns these
     * rules are prescribed by the bodies and not ours to invent, so the escape
     * hatch exists for the day one of them differs — but the standard rule is
     * what answers, and nobody has to keep it in step by hand.
     */
    public function creditsFor(Accreditation $accreditation): string
    {
        $override = $this->accreditations()
            ->where('accreditations.id', $accreditation->getKey())
            ->first()
            ?->pivot?->getAttribute('credits');

        return $override === null ? $this->standardCredits() : (string) $override;
    }

    /** Whether this webinar is long enough to be worth any credit at all. */
    public function earnsCredits(): bool
    {
        return (float) $this->standardCredits() > 0;
    }

    /** @return MorphMany<Offering, $this> */
    public function offerings(): MorphMany
    {
        return $this->morphMany(Offering::class, 'offerable');
    }

    // Scopes ----------------------------------------------------------------

    /** @param  Builder<Webinar>  $query */
    public function scopePublic(Builder $query): void
    {
        $query->whereIn('status', WebinarStatus::publicValues());
    }

    /** @param  Builder<Webinar>  $query */
    public function scopeOfType(Builder $query, WebinarType $type): void
    {
        $query->where('type', $type->value);
    }

    /** @param  Builder<Webinar>  $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at');
    }

    // Behaviour -------------------------------------------------------------

    /**
     * The visible content sections, in their saved order, as the view and tab
     * bar consume them.
     *
     * Sections empty of both heading and body are dropped — a bare heading
     * reads as a fault. A section with a body but no heading is kept: it renders
     * its body and simply earns no tab. Anchors are derived from the row id, so
     * they stay stable as headings are renamed — except the first (the overview),
     * which gets the fixed `overview` anchor the hero's "Click to Know More"
     * button links to.
     *
     * @return array<int, array{id: string, heading: string, body: string}>
     */
    public function contentSections(): array
    {
        $sections = $this->sections
            ->where('is_visible', true)
            ->map(static fn (WebinarSection $section): array => [
                'id' => 'section-'.$section->getKey(),
                'heading' => (string) $section->heading,
                'body' => (string) $section->body,
            ])
            ->filter(static fn (array $section): bool => trim($section['body']) !== '' || trim($section['heading']) !== '')
            ->values()
            ->all();

        // The overview is the first content section; give it the stable anchor
        // the hero button (href="#overview") and its own tab point at.
        if ($sections !== []) {
            $sections[0]['id'] = 'overview';
        }

        return $sections;
    }

    /**
     * Whether the public detail page may render this.
     *
     * Draft and archived webinars keep their slugs, so without this they would
     * resolve through route-model binding and quietly publish an unfinished
     * session.
     */
    public function isPubliclyVisible(): bool
    {
        return in_array($this->status->value, WebinarStatus::publicValues(), true);
    }

    /**
     * The accrediting bodies as the hero shows them — "HRCI, SHRM & CPE".
     *
     * Bodies only; the credit hours sit on the pivot and differ per body, so a
     * single summary line cannot carry them honestly.
     */
    public function creditBodies(): string
    {
        $codes = $this->accreditations->pluck('code')->all();

        if ($codes === []) {
            return 'Not accredited';
        }

        if (count($codes) === 1) {
            return $codes[0];
        }

        $last = array_pop($codes);

        return implode(', ', $codes).' & '.$last;
    }

    /**
     * The scheduled time shown in both US zones — "10:00 AM PT / 1:00 PM ET".
     *
     * It is one instant rendered in Pacific and Eastern, so it is correct for any
     * US session whatever `timezone` was entered — this is a US audience (§1),
     * and PT/ET is the convention the comp uses. Never print the raw IANA zone.
     */
    public function scheduledTimeLabel(): ?string
    {
        if ($this->scheduled_at === null) {
            return null;
        }

        $pacific = $this->scheduled_at->copy()->setTimezone('America/Los_Angeles')->format('g:i A');
        $eastern = $this->scheduled_at->copy()->setTimezone('America/New_York')->format('g:i A');

        return $pacific.' PT / '.$eastern.' ET';
    }

    /** The scheduled date in Eastern — "Aug 22, 2025" — paired with the time label. */
    public function scheduledDateLabel(): ?string
    {
        return $this->scheduled_at?->copy()->setTimezone('America/New_York')->format('M j, Y');
    }

    /**
     * The attendee notice shown on the detail page.
     *
     * Per-webinar text wins; otherwise the site-wide default from Settings.
     * Most webinars leave the override empty, which is why it is a fallback
     * rather than a copied-in default — editing the setting must update every
     * webinar that has not overridden it.
     */
    public function resolvedInstructions(): ?string
    {
        $own = trim((string) $this->attendee_instructions);

        return $own !== ''
            ? $own
            : (Setting::get('webinars.default_instructions') ?: null);
    }

    /**
     * Why this webinar cannot go live yet — empty means it can.
     *
     * Publishing is a gated action rather than a status dropdown. A webinar with
     * no active offering renders in the catalogue and then fails at
     * add-to-cart, because a price only exists in `offerings`; one with no
     * accreditation sells CEU credits it cannot award.
     *
     * @return array<int, string>
     */
    public function publishableProblems(): array
    {
        $problems = [];

        if ($this->speaker_id === null) {
            $problems[] = 'It has no speaker.';
        }

        if ($this->type->requiresSchedule() && $this->scheduled_at === null) {
            $problems[] = 'A live webinar needs a date and time.';
        }

        if ($this->offerings()->where('is_active', true)->doesntExist()) {
            $problems[] = 'It has no active offering, so there is nothing to buy.';
        }

        if ($this->accreditations()->doesntExist()) {
            $problems[] = 'It awards no accreditation credits.';
        }

        /*
         * Accredited but too short to earn anything — 30 minutes of content is
         * half a credit, so a 20-minute session carries bodies that award it
         * nothing. Publishing that sells CEU credits the business cannot award,
         * which is the one thing §1 says customers are actually buying.
         */
        if ($this->accreditations()->exists() && ! $this->earnsCredits()) {
            $problems[] = sprintf(
                'At %d minutes it earns no credits. %s',
                (int) $this->duration_minutes,
                CreditHours::describeRule(),
            );
        }

        foreach ($this->disallowedFormats() as $format) {
            $problems[] = "A {$this->type->label()} webinar cannot sell the {$format->label()} format.";
        }

        return $problems;
    }

    /**
     * Formats being sold that this type has no business selling — a recorded
     * webinar offering `live` would take money for a session that will never
     * happen.
     *
     * @return array<int, Format>
     */
    public function disallowedFormats(): array
    {
        return $this->offerings()
            ->where('is_active', true)
            ->with('formats')
            ->get()
            ->flatMap(fn (Offering $offering): iterable => $offering->formatEnums())
            ->unique()
            ->reject(fn (Format $format): bool => $this->type->allows($format))
            ->values()
            ->all();
    }

    // Media -----------------------------------------------------------------

    /** The catalogue-card image URL, or null when none is picked. */
    public function cardImageUrl(): ?string
    {
        return $this->cardMedia?->detailImageUrl();
    }

    /** The hero-background image URL, or null when none is picked. */
    public function bannerImageUrl(): ?string
    {
        return $this->bannerMedia?->detailImageUrl();
    }

    /**
     * The library media this webinar points at, for the delete guard.
     *
     * @return array<string, int|null>
     */
    public function mediaUsageMap(): array
    {
        return [
            'card image' => $this->card_media_id,
            'background image' => $this->banner_media_id,
        ];
    }

    // SEO — CLAUDE.md §5.6 / client SEO brief -------------------------------

    /**
     * The webinar's own social image is its background, then its card.
     *
     * This is the brief's §13/§15 rule made structural: "use the relevant image,
     * the webinar banner, not the company logo." Because the rule lives on the
     * model, the renderer never has to special-case webinars — it asks every
     * record for its own image and the right thing happens.
     */
    public function defaultSeoOgImageUrl(): ?string
    {
        return $this->bannerImageUrl() ?? $this->cardImageUrl();
    }

    /** The public path, so a slug change leaves a 301 behind (brief §4). */
    public function seoPathForSlug(string $slug): ?string
    {
        return '/webinars/'.$slug;
    }
}
