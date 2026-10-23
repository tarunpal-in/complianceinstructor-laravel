<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Import\Importers\EntitlementImporter;
use App\Import\Legacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 4 of the migration — the access ledger.
 *
 * Derives entitlements from the imported orders (Phase 3): the one step that
 * makes a migrated purchase actually grant access (§5.2 / §9). Idempotent and
 * transactional; `--dry-run` reports the counts and rolls everything back.
 * Reads from the app database, so it does not need the legacy source, but the
 * guard is kept so the phases are run in order.
 */
class ImportEntitlements extends Command
{
    protected $signature = 'import:entitlements {--dry-run : Report what would change without writing anything}';

    protected $description = 'Derive the entitlement ledger from the imported orders.';

    public function handle(): int
    {
        if (! Legacy::configured()) {
            $this->error('The legacy database is not configured. Run import:status first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Entitlement import — DRY RUN (nothing will be saved)' : 'Entitlement import');
        $this->newLine();

        DB::beginTransaction();

        try {
            $result = (new EntitlementImporter)->run();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Import failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('  <info>Entitlements</info>  %s', $result->summary()));

        foreach ($result->notes as $note) {
            $this->line('      <comment>'.$note.'</comment>');
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete — rolled back, nothing saved.' : 'Entitlements imported.');

        return self::SUCCESS;
    }
}
