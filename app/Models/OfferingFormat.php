<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One format an offering grants — docs/webinars-module.md §2.1.
 *
 * A model rather than a bare pivot because there is no `formats` table for
 * belongsToMany to point at: the format is an enum value, not a row.
 */
class OfferingFormat extends Model
{
    protected $table = 'offering_format';

    protected $fillable = ['offering_id', 'format'];

    protected function casts(): array
    {
        return ['format' => Format::class];
    }

    /** @return BelongsTo<Offering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }
}
