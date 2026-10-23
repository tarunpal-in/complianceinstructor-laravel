<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Import\Importer;
use App\Import\Importers\RedirectImporter;
use App\Import\Importers\SeoImporter;
use App\Import\Legacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 5 of the migration — content & SEO.
 *
 * The webinars' Yoast SEO overrides and the 301s from the old `/product/{slug}`
 * URLs to the new `/webinars/{slug}` pages — what keeps the catalogue's search
 * traffic through the cutover. Idempotent and transactional; `--dry-run` rolls
 * everything back.
 */
class ImportContent extends Command
{
    protected $signature = 'import:content {--dry-run : Report what would change without writing anything}';

    protected $description = 'Import SEO overrides and URL redirects from the legacy store.';

    public function handle(): int
    {
        if (! Legacy::configured()) {
            $this->error('The legacy database is not configured. Run import:status first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        /** @var array<int, Importer> $importers */
        $importers = [
            new SeoImporter,
            new RedirectImporter,
        ];

        $this->info($dryRun ? 'Content import — DRY RUN (nothing will be saved)' : 'Content import');
        $this->newLine();

        DB::beginTransaction();

        try {
            foreach ($importers as $importer) {
                $result = $importer->run();
                $this->line(sprintf('  <info>%-10s</info> %s', $importer->label(), $result->summary()));

                foreach (array_slice($result->notes, 0, 10) as $note) {
                    $this->line('      <comment>'.$note.'</comment>');
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
        $this->info($dryRun ? 'Dry run complete — rolled back, nothing saved.' : 'Content imported.');

        return self::SUCCESS;
    }
}
