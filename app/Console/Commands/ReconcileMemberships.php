<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Memberships\ReconcileSubscription;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * The daily safety net for membership snapshots — CLAUDE.md §8 / P4.
 *
 * The webhook listener reconciles a subscription the moment Stripe reports a
 * change, so in the normal case this sweep finds nothing to do. It exists for
 * the case the webhook did not arrive: a dropped delivery would otherwise leave
 * a member on the allowances of a plan they no longer pay for until something
 * else touched the row. Running daily bounds that drift to a day.
 *
 * It reconciles only the plan snapshot. Status and expiry are read at query time
 * from the columns Cashier maintains, so they need no sweep.
 */
class ReconcileMemberships extends Command
{
    protected $signature = 'memberships:reconcile';

    protected $description = 'Re-snapshot any subscription whose plan no longer matches the price Stripe bills.';

    public function handle(ReconcileSubscription $reconcile): int
    {
        $reconciled = 0;

        Subscription::query()
            ->whereNotNull('stripe_price')
            ->each(function (Subscription $subscription) use ($reconcile, &$reconciled): void {
                if ($reconcile($subscription)) {
                    $reconciled++;
                    $this->line("Reconciled subscription {$subscription->getKey()} to its billed plan.");
                }
            });

        $this->info("Done. {$reconciled} subscription(s) reconciled.");

        return self::SUCCESS;
    }
}
