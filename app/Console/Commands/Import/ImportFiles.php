<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Import\FileMigrator;
use App\Models\WebinarAsset;
use Illuminate\Console\Command;

/**
 * Phase 6b of the migration — move the WP-hosted delivery files onto the private
 * disk (R2).
 *
 * Fetches each webinar asset still pointing at a wp-content URL and stores it on
 * the webinar-assets disk, so delivery no longer depends on the old site and is
 * served privately (§9). Slow and network-bound, so it runs with a progress bar
 * and `--limit` for a trial; `--dry-run` only counts. Re-runnable — a file
 * already moved is skipped. GoToWebinar recordings are left as URLs.
 */
class ImportFiles extends Command
{
    protected $signature = 'import:files {--dry-run : Count what would move without downloading} {--limit=0 : Move at most this many (0 = all)}';

    protected $description = 'Copy WP-hosted webinar files onto the private disk (R2).';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $query = WebinarAsset::query()
            ->whereNull('disk_path')
            ->where('url', 'like', '%wp-content%');

        $total = (clone $query)->count();

        if ($limit > 0) {
            $query->limit($limit);
        }

        $this->info(($dryRun ? 'File migration — DRY RUN' : 'File migration').": {$total} WP-hosted files to move".($limit > 0 ? " (this run capped at {$limit})" : '').'.');

        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        $disk = (string) config('filesystems.webinar_assets');
        $migrator = new FileMigrator($disk);

        $downloaded = 0;
        $failed = 0;
        $failedIds = [];

        $assets = $query->get();
        $bar = $this->output->createProgressBar($assets->count());
        $bar->start();

        foreach ($assets as $asset) {
            $outcome = $migrator->migrate($asset);

            if ($outcome === 'downloaded') {
                $downloaded++;
            } elseif ($outcome === 'failed') {
                $failed++;
                $failedIds[] = $asset->getKey();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Moved {$downloaded}; failed {$failed}.");

        if ($failedIds !== []) {
            $this->warn('Failed asset ids: '.implode(', ', array_slice($failedIds, 0, 20)));
        }

        return self::SUCCESS;
    }
}
