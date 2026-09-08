<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Format;
use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use Database\Factories\WebinarFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
class Webinar extends Model implements HasMedia
{
    /** @use HasFactory<WebinarFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'type', 'status', 'title', 'slug', 'summary',
        'description', 'overview', 'background',
        'who_should_attend', 'why_attend', 'session_highlights',
        'attendee_instructions',
        'speaker_id', 'industry_id',
        'scheduled_at', 'timezone', 'duration_minutes', 'seats_total',
    ];

    protected function casts(): array
    {
        return [
            'type' => WebinarType::class,
            'status' => WebinarStatus::class,
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'seats_total' => 'integer',
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

    /** @return BelongsToMany<Accreditation, $this> */
    public function accreditations(): BelongsToMany
    {
        // Table stated explicitly: Laravel would derive `accreditation_webinar`
        // alphabetically, but §5.3 names it webinar_accreditation.
        return $this->belongsToMany(Accreditation::class, 'webinar_accreditation')
            ->withPivot('credits')
            ->withTimestamps();
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
     * The long-form sections, in the order the comp stacks them.
     *
     * Empty ones are dropped rather than rendered as a heading with nothing
     * under it — the client will not always have four sections of copy, and a
     * bare heading reads as a fault.
     *
     * The anchors are what the tab bar scrolls to, so they are stable strings
     * and not derived from the heading text.
     *
     * @return array<int, array{id: string, heading: string, body: string}>
     */
    public function contentSections(): array
    {
        $candidates = [
            ['id' => 'overview', 'heading' => 'Webinar Overview', 'body' => $this->overview],
            ['id' => 'highlights', 'heading' => 'Session Highlights', 'body' => $this->session_highlights],
            ['id' => 'why-attend', 'heading' => 'Why You Should Attend', 'body' => $this->why_attend],
            ['id' => 'who-attends', 'heading' => 'Who Should Attend', 'body' => $this->who_should_attend],
        ];

        return array_values(array_filter(
            $candidates,
            static fn (array $section): bool => trim((string) $section['body']) !== '',
        ));
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

    public function registerMediaCollections(): void
    {
        // Two collections rather than two columns: the catalogue card is a
        // 16:10 thumbnail, the detail page a full-bleed hero.
        $this->addMediaCollection('card')->singleFile();
        $this->addMediaCollection('banner')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('card')
            ->fit(Fit::Crop, 640, 400);

        $this->addMediaConversion('banner')
            ->fit(Fit::Crop, 1920, 640);
    }
}
