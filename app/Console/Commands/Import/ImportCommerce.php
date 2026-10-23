<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Import\Importer;
use App\Import\Importers\CouponImporter;
use App\Import\Importers\CouponRedemptionImporter;
use App\Import\Importers\OrderImporter;
use App\Import\Importers\PaymentImporter;
use App\Import\Legacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 3 of the migration — commerce history.
 *
 * Coupons, then orders (with their line items, format snapshots and attendees),
 * then the payments and refunds, then the coupon redemptions — in dependency
 * order. Idempotent and transactional, like the catalogue import; `--dry-run`
 * rolls everything back. Entitlements (the access ledger) are Phase 4 and are
 * derived from what this leaves behind.
 */
class ImportCommerce extends Command
{
    protected $signature = 'import:commerce {--dry-run : Report what would change without writing anything}';

    protected $description = 'Import commerce history (coupons, orders, payments, refunds, redemptions) from the legacy store.';

    public function handle(): int
    {
        if (! Legacy::configured()) {
            $this->error('The legacy database is not configured. Run import:status first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        /** @var array<int, Importer> $importers */
        $importers = [
            new CouponImporter,
            new OrderImporter,
            new PaymentImporter,
            new CouponRedemptionImporter,
        ];

        $this->info($dryRun ? 'Commerce import — DRY RUN (nothing will be saved)' : 'Commerce import');
        $this->newLine();

        DB::beginTransaction();

        try {
            foreach ($importers as $importer) {
                $result = $importer->run();

                $this->line(sprintf('  <info>%-11s</info> %s', $importer->label(), $result->summary()));

                foreach (array_slice($result->notes, 0, 10) as $note) {
                    $this->line('      <comment>'.$note.'</comment>');
                }

                if (count($result->notes) > 10) {
                    $this->line(sprintf('      <comment>… and %d more</comment>', count($result->notes) - 10));
                }
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error('Import failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete — rolled back, nothing saved.' : 'Commerce imported.');

        return self::SUCCESS;
    }
}
