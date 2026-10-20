<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A study-material file that accompanies a webinar — CLAUDE.md §6 / P5.
 *
 * Supplementary to the purchased deliverable (a slide deck, a worksheet, an
 * image), handed to any learner who holds access to the webinar in any format.
 * Stored on the private webinar-assets disk (R2 in production) and served
 * through a signed, expiring URL by entitlement (§9) — never a guessable path.
 *
 * @property string $disk_path
 * @property bool $is_active
 */
class WebinarHandout extends Model
{
    /** @use HasFactory<\Database\Factories\WebinarHandoutFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'webinar_id', 'label', 'disk_path', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Webinar, $this> */
    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }

    /** What the learner sees — the admin label, else the file's own name. */
    public function displayName(): string
    {
        $label = is_string($this->label) ? trim($this->label) : '';

        return $label !== '' ? $label : Str::afterLast($this->disk_path, '/');
    }

    /** A short, upper-case extension for the little file-type badge. */
    public function extension(): string
    {
        return Str::upper(Str::afterLast($this->disk_path, '.'));
    }
}
