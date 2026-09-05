<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AccreditationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property-read \Illuminate\Database\Eloquent\Relations\Pivot|null $pivot
 *
 * CLAUDE.md §5.3 — an accrediting body: HRCI, SHRM, CPE, APA.
 *
 * §12 has their credit rules "prescribed and not negotiable", so credits live
 * on the pivot per webinar rather than here — the same body awards different
 * amounts for different sessions.
 */
class Accreditation extends Model
{
    /** @use HasFactory<AccreditationFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'description', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsToMany<Webinar, $this> */
    public function webinars(): BelongsToMany
    {
        return $this->belongsToMany(Webinar::class, 'webinar_accreditation')
            ->withPivot('credits')
            ->withTimestamps();
    }
}
