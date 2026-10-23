<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Import\Importers\UserImporter;
use App\Import\Legacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 2 of the migration — customers.
 *
 * Imports real customer accounts (customer role or billing on file), carrying
 * the WordPress password hash across so no one resets. Idempotent and
 * transactional; `--dry-run` reports the counts and rolls everything back.
 */
class ImportUsers extends Command
{
    protected $signature = 'import:users {--dry-run : Report what would change without writing anything}';

    protected $description = 'Import customer accounts from the legacy store, carrying passwords across.';

    public function handle(): int
    {
        if (! Legacy::configured()) {
            $this->error('The legacy database is not configured. Run import:status first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'User import — DRY RUN (nothing will be saved)' : 'User import');
        $this->newLine();

        DB::beginTransaction();

        try {
            $result = (new UserImporter)->run();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Import failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('  <info>Users</info>  %s', $result->summary()));

        foreach (array_slice($result->notes, 0, 10) as $note) {
            $this->line('      <comment>'.$note.'</comment>');
        }

        if (count($result->notes) > 10) {
            $this->line(sprintf('      <comment>… and %d more</comment>', count($result->notes) - 10));
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete — rolled back, nothing saved.' : 'Users imported.');

        return self::SUCCESS;
    }
}
