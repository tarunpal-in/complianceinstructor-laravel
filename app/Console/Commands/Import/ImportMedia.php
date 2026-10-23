<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Import\Importers\MediaImporter;
use App\Import\Legacy;
use Illuminate\Console\Command;

/**
 * Phase 6c of the migration — attach the old WordPress images to the imported
 * records from the local `uploads` backup (uploads/uploads).
 *
 * Webinar featured images (→ card + banner), industry category images,
 * embedded description images, and best-effort speaker portraits. Reads the
 * legacy dump for the attachment metadata that maps a record to a file, and
 * never writes to the backup. Re-runnable; `--dry-run` only counts.
 */
class ImportMedia extends Command
{
    protected $signature = 'import:media
        {--dry-run : Resolve and count what would be attached, writing nothing}
        {--limit=0 : Attach at most this many per section (0 = all)}
        {--only=* : Limit to sections: webinars, industries, content, speakers}';

    protected $description = 'Attach the old WordPress images to imported records from the uploads backup.';

    private const SECTIONS = ['webinars', 'industries', 'content', 'speakers'];

    public function handle(): int
    {
        if (! Legacy::configured()) {
            $this->error(
                'The legacy database is not configured. Set LEGACY_DB_DATABASE (and credentials) '
                .'and load the WordPress dump before running the import.'
            );

            return self::FAILURE;
        }

        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('only')));
        $unknown = array_diff($only, self::SECTIONS);

        if ($unknown !== []) {
            $this->error('Unknown --only section(s): '.implode(', ', $unknown).'. Valid: '.implode(', ', self::SECTIONS).'.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info(($dryRun ? 'Media import — DRY RUN' : 'Media import').($limit > 0 ? " (capped at {$limit} per section)" : '').'.');
        $this->newLine();

        $stats = (new MediaImporter)->run($only, $dryRun, $limit);

        foreach ($stats as $section => $counts) {
            $cells = [];
            foreach ($counts as $key => $value) {
                $cells[] = "{$key}: {$value}";
            }
            $this->line('  '.str_pad(ucfirst($section), 12).implode('   ', $cells));
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete — nothing written.' : 'Media import complete.');

        if (! $dryRun) {
            $this->comment('Image conversions (thumb/preview) are queued — run `php artisan queue:work` to generate them. Until then the originals are served.');
        }

        return self::SUCCESS;
    }
}
