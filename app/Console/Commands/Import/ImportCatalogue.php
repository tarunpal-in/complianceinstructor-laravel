<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Import\Importer;
use App\Import\Importers\IndustryImporter;
use App\Import\Importers\OfferingImporter;
use App\Import\Importers\SpeakerImporter;
use App\Import\Importers\WebinarImporter;
use App\Import\Legacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 1 of the migration — the catalogue: speakers, industries, webinars and
 * their offerings, in dependency order.
 *
 * Idempotent (safe to re-run) and transactional: every importer runs inside one
 * transaction so a webinar can resolve the speaker and industry just written,
 * and `--dry-run` rolls the whole thing back at the end — the counts are what
 * *would* happen, with nothing persisted. Accreditation and delivery assets are
 * handled separately (accreditation needs the client's body list; assets tie to
 * the file migration).
 */
class ImportCatalogue extends Command
{
    protected $signature = 'import:catalogue {--dry-run : Report what would change without writing anything}';

    protected $description = 'Import the webinar catalogue (speakers, industries, webinars, offerings) from the legacy store.';

    public function handle(): int
    {
        if (! Legacy::configured()) {
            $this->error('The legacy database is not configured. Run import:status first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        /** @var array<int, Importer> $importers */
        $importers = [
            new SpeakerImporter,
            new IndustryImporter,
            new WebinarImporter,
            new OfferingImporter,
        ];

        $this->info($dryRun ? 'Catalogue import — DRY RUN (nothing will be saved)' : 'Catalogue import');
        $this->newLine();

        DB::beginTransaction();

        try {
            foreach ($importers as $importer) {
                $result = $importer->run();

                $this->line(sprintf('  <info>%-12s</info> %s', $importer->label(), $result->summary()));

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

        if ($dryRun) {
            DB::rollBack();
            $this->newLine();
            $this->info('Dry run complete — rolled back, nothing saved.');
        } else {
            DB::commit();
            $this->newLine();
            $this->info('Catalogue imported.');
        }

        return self::SUCCESS;
    }
}
