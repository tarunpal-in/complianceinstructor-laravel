<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllowanceWindow;
use App\Enums\Format;
use App\Models\Concerns\DescribesAllowance;
use Database\Factories\PlanEntitlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of what a plan grants — CLAUDE.md §5.5.
 *
 * A row, not a column: "4 live + unlimited transcripts" is data here, where in
 * the predecessor it was 17 boolean and counter columns and a migration every
 * time the client invented a tier.
 *
 * @property Format $format
 * @property int|null $quantity
 * @property AllowanceWindow $window
 */
class PlanEntitlement extends Model
{
    /** @use HasFactory<PlanEntitlementFactory> */
    use DescribesAllowance, HasFactory;

    protected $fillable = ['membership_plan_id', 'format', 'quantity', 'window'];

    protected function casts(): array
    {
        return [
            'format' => Format::class,
            'window' => AllowanceWindow::class,
        ];
    }

    /** @return BelongsTo<MembershipPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }
}
