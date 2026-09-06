<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MediaKind;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * @property string|null $hash
 * @property string $alt
 * @property string $caption
 * @property \Illuminate\Support\Collection<int, MediaUsage> $usages
 * @property MediaDocument|null $document
 *
 * Our Media, replacing spatie's through `media-library.media_model`.
 *
 * Everything here is additive: the library needs a usage index, a content hash,
 * extracted document text and alt/caption, none of which spatie's model has.
 * Extending rather than wrapping keeps one media table and one API, which
 * docs/media-manager.md §3 explains at length.
 */
class Media extends SpatieMedia
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    /** The collection every library file lives in. */
    public const LIBRARY_COLLECTION = 'library';

    /** @return HasMany<MediaUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class);
    }

    /** @return HasOne<MediaDocument, $this> */
    public function document(): HasOne
    {
        return $this->hasOne(MediaDocument::class);
    }

    /** @return MorphToMany<Tag, $this> */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Whether this row is a browsable, reusable library file rather than one
     * attached to a webinar, speaker or industry.
     */
    public function isLibraryItem(): bool
    {
        return $this->model_type === MediaFolder::class;
    }

    public function kind(): MediaKind
    {
        return MediaKind::forMime((string) $this->mime_type, (string) $this->extension);
    }

    /**
     * Alt text, caption and uploader live in `custom_properties`: they are
     * displayed, never filtered on, so they do not earn columns.
     *
     * @return Attribute<string, string>
     */
    protected function alt(): Attribute
    {
        return Attribute::make(
            get: fn (): string => (string) $this->getCustomProperty('alt', ''),
            set: fn (?string $value): array => $this->mergedCustomProperties('alt', (string) $value),
        );
    }

    /** @return Attribute<string, string> */
    protected function caption(): Attribute
    {
        return Attribute::make(
            get: fn (): string => (string) $this->getCustomProperty('caption', ''),
            set: fn (?string $value): array => $this->mergedCustomProperties('caption', (string) $value),
        );
    }

    /**
     * `custom_properties` is a single JSON column, so setting one key means
     * writing the whole array back. Merging rather than replacing keeps the
     * uploader and the other field intact.
     *
     * Encoded here rather than left as an array: what an Attribute setter
     * returns is merged into the model's *raw* attributes, so the parent's
     * `custom_properties => array` cast never runs on it and the next read
     * would hand json_decode() an array.
     *
     * @return array{custom_properties: string}
     */
    private function mergedCustomProperties(string $key, mixed $value): array
    {
        /** @var array<string, mixed> $properties */
        $properties = $this->custom_properties ?? [];
        $properties[$key] = $value;

        return ['custom_properties' => (string) json_encode($properties)];
    }

    /**
     * An image with no alt text is a WCAG AA failure, which §7 P9 audits for.
     * Only images can fail it — a PDF is a link, not an image.
     */
    public function needsAltText(): bool
    {
        return $this->kind() === MediaKind::Image && $this->alt === '';
    }

    /**
     * The grid thumbnail, or null when this kind of file cannot have one.
     *
     * Three cases end in null and all are normal: an office document, which no
     * conversion service here can render; a PDF on a server without poppler or
     * Ghostscript (docs/media-manager.md §6.1); and a file whose conversion is
     * still queued. The card falls back to a mime icon rather than a broken
     * image, so `getUrl('thumb')` is never called speculatively — it happily
     * returns a path to a file that was never generated.
     */
    public function thumbnailUrl(): ?string
    {
        if ($this->hasGeneratedConversion('thumb')) {
            return $this->getUrl('thumb');
        }

        // SVG skips the image pipeline deliberately, so it has no conversion to
        // wait for and the original is already the right thing to show.
        if ($this->kind() === MediaKind::Image && strtolower((string) $this->extension) === 'svg') {
            return $this->getUrl();
        }

        return null;
    }

    /**
     * The larger rendition for the detail panel, falling back to the original.
     *
     * Not `previewUrl()`: the parent already has one as an Attribute accessor
     * backing `$media->preview_url`, and overriding it with a plain method
     * breaks the signature.
     */
    public function detailImageUrl(): ?string
    {
        if ($this->hasGeneratedConversion('preview')) {
            return $this->getUrl('preview');
        }

        return $this->kind() === MediaKind::Image ? $this->getUrl() : null;
    }

    /**
     * Human size. Spatie has `human_readable_size`, but it reports "1 MB" for
     * 1,000,000 bytes; disks are sold that way and files are not.
     */
    public function readableSize(): string
    {
        $bytes = (float) $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];

        $power = $bytes > 0 ? (int) min(floor(log($bytes, 1024)), count($units) - 1) : 0;
        $value = $bytes / (1024 ** $power);

        return ($power === 0 ? (string) (int) $value : number_format($value, 1)).' '.$units[$power];
    }
}
