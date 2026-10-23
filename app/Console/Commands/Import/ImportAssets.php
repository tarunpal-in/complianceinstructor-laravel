<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Import\Importers\AssetImporter;
use App\Import\Legacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 6 of the migration — delivery assets.
 *
 * Imports the recording/transcript/download URLs from the variations' WooCommerce
 * downloadable files as webinar assets, so migrated customers can reach their
 * purchases. The files themselves are served by the live site and GoToWebinar
 * through the transition; copying the WP-hosted ones onto R2 is a separate step
 * that needs the uploads directory. Idempotent and transactional; `--dry-run`
 * rolls everything back.
 */
class ImportAssets extends Command
{
    protected $signature = 'import:assets {--dry-run : Report what would change without writing anything}';

    protected $description = 'Import webinar delivery assets (recording/transcript/download URLs) from the legacy store.';

    public function handle(): int
    {
        if (! Legacy::configured()) {
            $this->error('The legacy database is not configured. Run import:status first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Asset import — DRY RUN (nothing will be saved)' : 'Asset import');
        $this->newLine();

        DB::beginTransaction();

        try {
            $result = (new AssetImporter)->run();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Import failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('  <info>Assets</info>  %s', $result->summary()));

        $dryRun ? DB::rollBack() : DB::commit();

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete — rolled back, nothing saved.' : 'Assets imported.');

        return self::SUCCESS;
    }
}
