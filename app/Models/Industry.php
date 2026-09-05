<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IndustryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
class Industry extends Model implements HasMedia
{
    /** @use HasFactory<IndustryFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
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
