<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CompletionRules;
use Database\Factories\AccreditationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property-read \Illuminate\Database\Eloquent\Relations\Pivot|null $pivot
 * @property int|null $logo_media_id
 *
 * CLAUDE.md §5.3 — an accrediting body: HRCI, SHRM, CPE, APA.
 *
 * §12 has their credit rules "prescribed and not negotiable", so credits live
 * on the pivot per webinar rather than here — the same body awards different
 * amounts for different sessions.
 */
class Accreditation extends Model
{
    /** @use HasFactory<AccreditationFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'description', 'logo_media_id', 'is_active', 'sort_order',
        'live_delay_hours', 'watch_tolerance_minutes',
    ];

    protected function casts(): array
    {
        return [
            'logo_media_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'live_delay_hours' => 'integer',
            'watch_tolerance_minutes' => 'integer',
        ];
    }

    /**
     * The body's logo — a library file picked with MediaPicker (§5.3), the same
     * reference model webinar images use, so replacing the file in the library
     * updates it everywhere.
     *
     * @return BelongsTo<Media, $this>
     */
    public function logoMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_media_id');
    }

    /** The logo's URL, or null when none is picked — for the homepage and schema. */
    public function logoUrl(): ?string
    {
        return $this->logoMedia?->detailImageUrl();
    }

    /**
     * The chip colour on a webinar card.
     *
     * The comp gives each body its own colour — HRCI amber, SHRM green — so the
     * chips read as accreditation marks rather than generic tags. Derived from
     * the code rather than stored: §1 fixes the set at HRCI, SHRM, CPE and APA,
     * so a column would be four rows of data pretending to be configuration.
     *
     * CPE and APA are not in the comp; theirs are drawn from the site palette
     * and are worth confirming with the client against the bodies' own brands.
     */
    public function brandColor(): string
    {
        return match (strtoupper($this->code)) {
            'HRCI' => '#996601',
            'SHRM' => '#1f6532',
            'CPE' => '#054376',
            'APA' => '#8a2b2b',
            default => '#4b5563',
        };
    }

    /** @return BelongsToMany<Webinar, $this> */
    public function webinars(): BelongsToMany
    {
        return $this->belongsToMany(Webinar::class, 'webinar_accreditation')
            ->withPivot('credits')
            ->withTimestamps();
    }
    // Completion rules — §12 ----------------------------------------------------

    /**
     * How long after a live session this body's certificates are issued.
     *
     * Its own figure where it has one, the global default otherwise. Most
     * bodies will never set this, and leaving it blank is what keeps one number
     * in one place rather than the same number copied onto four records.
     */
    public function liveDelayHours(): int
    {
        return $this->live_delay_hours ?? CompletionRules::liveDelayHours();
    }

    /** How much of a recording this body allows to be left unwatched. */
    public function watchToleranceMinutes(): int
    {
        return $this->watch_tolerance_minutes ?? CompletionRules::watchToleranceMinutes();
    }

    public function overridesLiveDelay(): bool
    {
        return $this->live_delay_hours !== null;
    }

    public function overridesWatchTolerance(): bool
    {
        return $this->watch_tolerance_minutes !== null;
    }

    /**
     * The shortest live delay any body could be using.
     *
     * The sweep looks for webinars whose delay has elapsed, and with per-body
     * delays "elapsed" is no longer one number — a session can be due for SHRM
     * at 24 hours and not due for HRCI until 48. Taking the smallest means the
     * sweep looks early and the per-body check inside decides; taking the
     * largest would silently hold back every body waiting for the slowest.
     */
    public static function shortestLiveDelayHours(): int
    {
        $overrides = self::query()
            ->whereNotNull('live_delay_hours')
            ->pluck('live_delay_hours')
            ->map(fn (mixed $hours): int => (int) $hours)
            ->all();

        // An array, not a spread: with no overrides the spread collapses to
        // min(int), which is a TypeError rather than the answer.
        return (int) min([CompletionRules::liveDelayHours(), ...$overrides]);
    }

    /** What this body's rules are, in a sentence, for the admin screen. */
    public function describeRules(): string
    {
        return sprintf(
            'Live certificates %s hours after the session; %s minutes of a recording may be skipped.',
            $this->liveDelayHours().($this->overridesLiveDelay() ? '' : ' (inherited)'),
            $this->watchToleranceMinutes().($this->overridesWatchTolerance() ? '' : ' (inherited)'),
        );
    }
}
