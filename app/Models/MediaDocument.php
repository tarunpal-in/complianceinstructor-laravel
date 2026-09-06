<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $media_id
 * @property int|null $page_count
 * @property string|null $extracted_text
 * @property \Illuminate\Support\Carbon|null $extracted_at
 * @property string|null $extract_error
 *
 * Text pulled out of a PDF so the library can be searched by contents.
 *
 * The client uploads `final-v3.pdf` and, matching on file name alone, never
 * finds it again. A sidecar rather than columns on `media` — see the migration
 * for why.
 */
class MediaDocument extends Model
{
    protected $primaryKey = 'media_id';

    public $incrementing = false;

    protected $fillable = ['media_id', 'page_count', 'extracted_text', 'extracted_at', 'extract_error'];

    protected function casts(): array
    {
        return ['extracted_at' => 'datetime'];
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * A scanned PDF has no text layer and extracts nothing. That is the expected
     * result, not a failure — OCR is out of scope — but the client should be
     * told rather than left wondering why searching does not find it.
     */
    public function hasNoTextLayer(): bool
    {
        return $this->extracted_at !== null
            && $this->extract_error === null
            && blank($this->extracted_text);
    }
}
