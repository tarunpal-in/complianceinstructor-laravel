<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $media_id
 * @property string $usable_type
 * @property int $usable_id
 * @property string $context
 *
 * One place a library file is used — docs/media-manager.md §4.
 *
 * Written by `RecordsMediaUsage` on save and never patched by hand, so the index
 * cannot drift from the records it describes.
 */
class MediaUsage extends Model
{
    protected $fillable = ['media_id', 'usable_type', 'usable_id', 'context'];

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return MorphTo<Model, $this> */
    public function usable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * How this reads in the "used in" panel — "Home page · hero.background".
     *
     * The owning record may have been deleted between the usage being written
     * and this being rendered, so a missing one degrades to the type rather than
     * throwing in a table cell.
     */
    public function describe(): string
    {
        $record = $this->usable;

        if ($record === null) {
            return class_basename($this->usable_type).' (deleted) · '.$this->context;
        }

        // getAttributes() rather than getAttribute(): Model::shouldBeStrict() is
        // on outside production, so asking a model for a column it does not have
        // throws rather than returning null — and this runs inside the delete
        // guard's modal, the worst possible place to raise an exception about a
        // missing label.
        $attributes = $record->getAttributes();

        $title = $attributes['title']
            ?? $attributes['name']
            ?? class_basename($this->usable_type).' #'.$this->usable_id;

        return "{$title} · {$this->context}";
    }
}
