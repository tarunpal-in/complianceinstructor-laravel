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
 *
 * One deliverable of a webinar — CLAUDE.md §5.3.
 *
 * The live join link, the recording, the transcript PDF, the materials. `url`
 * for something hosted elsewhere; `disk_path` for a file on the private disk,
 * which §9 requires be served through a signed expiring URL by entitlement and
 * never a guessable path.
 */
class WebinarAsset extends Model
{
    /** @use HasFactory<\Database\Factories\WebinarAssetFactory> */
    use HasFactory;

    protected $fillable = [
        'webinar_id', 'format', 'label', 'url', 'disk_path', 'available_from', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'format' => Format::class,
            'available_from' => 'datetime',
            'is_active' => 'boolean',
        ];
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
