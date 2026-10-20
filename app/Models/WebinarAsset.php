<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Format;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Format $format
 * @property \Illuminate\Support\Carbon|null $available_from
 * @property bool $is_active
 * @property bool $embed
 *
 * One deliverable of a webinar — CLAUDE.md §5.3.
 *
 * The live join link, the recording, the transcript PDF, the materials. `url`
 * for something hosted elsewhere; `disk_path` for a file on the private disk,
 * which §9 requires be served through a signed expiring URL by entitlement and
 * never a guessable path.
 *
 * `embed` is how a recording is delivered: off, the asset opens directly — an
 * external link (Dropbox, a CDN) in a new tab, or a signed download; on, it
 * plays inside the site's own player (an R2 upload, or a platform's embed URL).
 */
class WebinarAsset extends Model
{
    /** @use HasFactory<\Database\Factories\WebinarAssetFactory> */
    use HasFactory;

    protected $fillable = [
        'webinar_id', 'format', 'label', 'url', 'disk_path', 'embed', 'available_from', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'format' => Format::class,
            'available_from' => 'datetime',
            'is_active' => 'boolean',
            'embed' => 'boolean',
        ];
    }

    /**
     * Whether this recording plays inside the site's own player rather than
     * opening directly. True only for something embeddable — a file on the
     * private disk, or a streaming platform's embed URL — so a plain share link
     * (Dropbox and the like, which will not iframe) always opens out instead.
     */
    public function isPlayable(): bool
    {
        return $this->embed && (filled($this->disk_path) || filled($this->url));
    }

    /** @return BelongsTo<Webinar, $this> */
    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    /**
     * Whether this has been released yet. A null `available_from` means
     * immediately; a future one is how a join link is held back until shortly
     * before the session (§7 P5).
     */
    public function isReleased(): bool
    {
        return $this->is_active
            && ($this->available_from === null || $this->available_from->isPast());
    }
}
