<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MediaFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * @property string $name
 * @property string $slug
 * @property int|null $parent_id
 *
 * The owner of every library file — docs/media-manager.md §3.
 *
 * It exists because spatie's `media` rows must belong to a model and a library
 * file belongs to nothing in particular. Making a folder the owner gives one
 * media table instead of two, and the client gets folders out of it.
 */
class MediaFolder extends Model implements HasMedia
{
    /** @use HasFactory<MediaFolderFactory> */
    use HasFactory, InteractsWithMedia;

    protected $fillable = ['name', 'slug', 'parent_id', 'sort_order'];

    /** @return BelongsTo<MediaFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<MediaFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(Media::LIBRARY_COLLECTION)
            ->useDisk(config('media-library.disk_name'));
    }

    /**
     * Two conversions, both queued (§4 rule 7) — a 12 MB PNG must not block the
     * request that uploaded it.
     *
     * `thumb` draws the grid; `preview` is the detail panel and most front-end
     * use. Deliberately no responsive-image set: it multiplies files, and
     * Cloudflare (§11) already resizes at the edge if that is ever wanted.
     */
    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        // An SVG is a document that can carry script, and it is served from the
        // same origin as the admin panel. It is stored and displayed as-is,
        // never fed to the image pipeline.
        $isVector = $media !== null && strtolower((string) $media->extension) === 'svg';

        if ($isVector) {
            return;
        }

        // keepOriginalImageFormat() and nonOptimized() come before fit():
        // manipulations go through Conversion::__call, which types its return as
        // the image driver, so anything chained after one is no longer known to
        // be a Conversion.
        $this->addMediaConversion('thumb')
            ->keepOriginalImageFormat()
            ->nonOptimized()
            ->fit(Fit::Contain, 400, 400);

        $this->addMediaConversion('preview')
            ->keepOriginalImageFormat()
            ->fit(Fit::Contain, 1400, 1400);
    }

    /**
     * The default destination for an upload that names no folder. Created on
     * demand so a fresh install has somewhere to put a file.
     */
    public static function uncategorised(): self
    {
        return static::firstOrCreate(
            ['slug' => 'uncategorised'],
            ['name' => 'Uncategorised', 'sort_order' => 0],
        );
    }
}
