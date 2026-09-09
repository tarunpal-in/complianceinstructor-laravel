<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $period_key
 * @property int $next_value
 *
 * The counter for one order-numbering period. See the migration for why this is
 * a stored counter rather than a lookup of the highest existing number.
 */
class OrderNumberSequence extends Model
{
    protected $fillable = ['period_key', 'next_value'];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }
}
