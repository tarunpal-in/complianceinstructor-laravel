<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Seoable;
use App\Enums\WebinarType;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\ReleasesUniqueNameOnDelete;
use Database\Factories\SpeakerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * CLAUDE.md §5.3 — speakers. Their credentials are most of what a CEU buyer is
 * actually paying for, so the portrait and title carry weight on the public page.
 */
class Speaker extends Model implements HasMedia, Seoable
{
    /** @use HasFactory<SpeakerFactory> */
    use HasFactory, HasSeo, InteractsWithMedia, ReleasesUniqueNameOnDelete, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'title', 'credentials', 'bio', 'linkedin_url', 'is_active',
        'rating', 'reviews_count', 'years_teaching', 'expertise',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reviews_count' => 'integer',
            'years_teaching' => 'integer',
            'expertise' => 'array',
        ];
    }

    /** @return HasMany<Webinar, $this> */
    public function webinars(): HasMany
    {
        return $this->hasMany(Webinar::class);
    }

    /** @param  Builder<Speaker>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The distinct accrediting bodies across this speaker's webinars — the
     * "HRCI · SHRM" line on the faculty card. Derived rather than stored so it
     * cannot disagree with the credits their sessions actually carry.
     *
     * @return array<int, string>
     */
    public function accreditationCodes(): array
    {
        return $this->accreditationsOffered()->pluck('code')->all();
    }

    /**
     * The distinct accreditations across this speaker's webinars, as records —
     * the "Accredited credits offered" panel. Derived from what their sessions
     * carry (§1 — credits are what customers are buying, so they are never
     * claimed loosely).
     *
     * @return \Illuminate\Support\Collection<int, Accreditation>
     */
    public function accreditationsOffered(): \Illuminate\Support\Collection
    {
        return $this->webinars
            ->flatMap(fn (Webinar $webinar): iterable => $webinar->accreditations)
            ->unique('id')
            ->sortBy('code')
            ->values();
    }

    /**
     * The distinct industries this speaker teaches — the "Topic domains" tags.
     *
     * @return \Illuminate\Support\Collection<int, Industry>
     */
    public function topicDomains(): \Illuminate\Support\Collection
    {
        return $this->webinars
            ->map(fn (Webinar $webinar): ?Industry => $webinar->industry)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * This speaker's webinars of a given type, newest first — for the Live /
     * Recorded tabs on the profile.
     *
     * @return \Illuminate\Support\Collection<int, Webinar>
     */
    public function webinarsOfType(WebinarType $type): \Illuminate\Support\Collection
    {
        return $this->webinars
            ->filter(fn (Webinar $webinar): bool => $webinar->type === $type && $webinar->isPubliclyVisible())
            ->sortByDesc('scheduled_at')
            ->values();
    }

    /** How many named attendees have ever been booked onto this speaker's sessions. */
    public function totalEnrolled(): int
    {
        return OrderAttendee::query()
            ->whereHas('orderItem', fn ($query) => $query->whereIn('webinar_id', $this->webinars()->select('id')))
            ->count();
    }

    /** The public profile path, so a slug change leaves a 301 behind (brief §4). */
    public function seoPathForSlug(string $slug): ?string
    {
        return '/speakers/'.$slug;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('portrait')->singleFile();
    }

    /**
     * The faculty grid crops square (1:1) and the detail page uses a larger
     * portrait. Conversions are queued, so an upload does not block the request.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 254, 254);

        $this->addMediaConversion('portrait')
            ->fit(Fit::Crop, 480, 600);
    }
}
