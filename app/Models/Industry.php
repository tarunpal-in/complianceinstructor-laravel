<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Seoable;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\ReleasesUniqueNameOnDelete;
use Database\Factories\IndustryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * CLAUDE.md §5.3 — industries. Also the second segment of a webinar's
 * breadcrumb, so the slug is part of the public URL.
 */
class Industry extends Model implements HasMedia, Seoable
{
    /** @use HasFactory<IndustryFactory> */
    use HasFactory, HasSeo, InteractsWithMedia, ReleasesUniqueNameOnDelete, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'is_active', 'sort_order', 'card_media_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'card_media_id' => 'integer',
        ];
    }

    /**
     * The library file picked as the card image (MediaPicker), or none.
     *
     * @return BelongsTo<\App\Models\Media, $this>
     */
    public function cardMedia(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Media::class, 'card_media_id');
    }

    /**
     * The homepage card image — the picked library file when set, otherwise the
     * old Spatie `card` upload, so images added before the switch still show.
     */
    public function cardImageUrl(): ?string
    {
        if ($this->card_media_id !== null) {
            return $this->cardMedia?->detailImageUrl();
        }

        return $this->getFirstMediaUrl('card') ?: null;
    }

    /** @return HasMany<Webinar, $this> */
    public function webinars(): HasMany
    {
        return $this->hasMany(Webinar::class);
    }

    public function registerMediaCollections(): void
    {
        // The homepage's Webinar Categories cards.
        $this->addMediaCollection('card')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('card')
            ->fit(Fit::Crop, 800, 534);
    }
}
