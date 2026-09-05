<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SpeakerFactory;
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
class Speaker extends Model implements HasMedia
{
    /** @use HasFactory<SpeakerFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'title', 'bio', 'linkedin_url', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Webinar, $this> */
    public function webinars(): HasMany
    {
        return $this->hasMany(Webinar::class);
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
