<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Offering;
use App\Models\Webinar;
use Illuminate\Console\Command;

/**
 * Give every webinar offering an add-on price in one shot.
 *
 * The add-on is the cart cross-sell (§5.3): once a webinar is in the cart, its
 * *other* offerings — single formats and bundles alike — are offered to add on
 * at `addon_price_cents`. The WooCommerce import left that column null
 * everywhere, so no add-ons showed. This sets a flat default across the
 * catalogue.
 *
 * Webinar offerings only — a package is not a webinar add-on. Idempotent, and
 * run again with a different value to change the default. It does not touch the
 * importer, so a per-offering price an admin sets later survives a catalogue
 * re-import.
 */
class SetOfferingAddonPrice extends Command
{
    protected $signature = 'offerings:addon-price
        {cents=6500 : The add-on price in cents (default $65)}
        {--dry-run : Report how many would change without writing}';

    protected $description = 'Set a flat add-on price on every webinar offering.';

    public function handle(): int
    {
        $cents = (int) $this->argument('cents');

        if ($cents < 0) {
            $this->error('The add-on price cannot be negative.');

            return self::FAILURE;
        }

        // Every webinar offering, single format or bundle. Package offerings are
        // left alone; soft-deleted rows are excluded by the default scope.
        $query = Offering::query()->where('offerable_type', Webinar::class);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("DRY RUN — would set addon_price_cents = {$cents} on {$count} webinar offering(s).");

            return self::SUCCESS;
        }

        $query->update(['addon_price_cents' => $cents]);

        $this->info('Set addon_price_cents = '.$cents.' ($'.number_format($cents / 100, 2).") on {$count} webinar offering(s).");

        return self::SUCCESS;
    }
}
