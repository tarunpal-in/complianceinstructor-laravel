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

    /**
     * The chip colour on a webinar card.
     *
     * The comp gives each body its own colour — HRCI amber, SHRM green — so the
     * chips read as accreditation marks rather than generic tags. Derived from
     * the code rather than stored: §1 fixes the set at HRCI, SHRM, CPE and APA,
     * so a column would be four rows of data pretending to be configuration.
     *
     * CPE and APA are not in the comp; theirs are drawn from the site palette
     * and are worth confirming with the client against the bodies' own brands.
     */
    public function brandColor(): string
    {
        return match (strtoupper($this->code)) {
            'HRCI' => '#996601',
            'SHRM' => '#1f6532',
            'CPE' => '#054376',
            'APA' => '#8a2b2b',
            default => '#4b5563',
        };
    }

    /** @return BelongsToMany<Webinar, $this> */
    public function webinars(): BelongsToMany
    {
        return $this->belongsToMany(Webinar::class, 'webinar_accreditation')
            ->withPivot('credits')
            ->withTimestamps();
    }
}
