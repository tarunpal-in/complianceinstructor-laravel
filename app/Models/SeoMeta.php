<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SeoMetaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One content record's SEO overrides — see the migration.
 *
 * @property string|null $title
 * @property string|null $description
 * @property string|null $keywords
 * @property int|null $og_image_media_id
 * @property string|null $canonical
 * @property string|null $robots
 */
class SeoMeta extends Model
{
    /** @use HasFactory<SeoMetaFactory> */
    use HasFactory;

    protected $table = 'seo_meta';

    protected $fillable = [
        'title', 'description', 'keywords', 'og_image_media_id', 'canonical', 'robots',
    ];

    /** @return MorphTo<Model, $this> */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The chosen social image's URL, or null.
     *
     * Resolved defensively: the id is held without a foreign key (the media
     * library owns its own rows), so the picked image may since have been
     * deleted, and a dangling id must yield no image rather than an error.
     */
    public function ogImageUrl(): ?string
    {
        if ($this->og_image_media_id === null) {
            return null;
        }

        return Media::query()->find($this->og_image_media_id)?->detailImageUrl();
    }
}
