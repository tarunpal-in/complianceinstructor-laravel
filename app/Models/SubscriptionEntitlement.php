<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllowanceWindow;
use App\Enums\Format;
use App\Models\Concerns\DescribesAllowance;
use Database\Factories\SubscriptionEntitlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one subscriber actually bought — the frozen copy of a plan's allowances.
 *
 * Agreed 2026-09-11: an active subscriber keeps the terms they signed up under.
 * These rows are that promise. P4's quota check reads them and never
 * `plan_entitlements`, which is the whole reason they exist — see the migration.
 *
 * @property Format $format
 * @property int|null $quantity
 * @property AllowanceWindow $window
 */
class SubscriptionEntitlement extends Model
{
    /** @use HasFactory<SubscriptionEntitlementFactory> */
    use DescribesAllowance, HasFactory;

    protected $fillable = ['subscription_id', 'format', 'quantity', 'window'];

    protected function casts(): array
    {
        return [
            'format' => Format::class,
            'window' => AllowanceWindow::class,
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
