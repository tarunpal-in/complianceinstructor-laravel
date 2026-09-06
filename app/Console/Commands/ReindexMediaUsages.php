<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ReferencesMedia;
use App\Models\MediaUsage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds `media_usages` from the records themselves.
 *
 * The index is written on save and should never drift, but "should never" is
 * not something to bet a delete guard on. A rebuild is cheap and settles the
 * question — run it after a bulk import, a manual database edit, or any time
 * the guard's answer looks wrong.
 */
class ReindexMediaUsages extends Command
{
    protected $signature = 'media:reindex-usages';

    protected $description = 'Rebuild the media usage index from the records that reference media';

    public function handle(): int
    {
        // Not narrowed to ReferencesMedia in the annotation: this is config a
        // person edits, so the check below is a real one rather than a
        // formality PHPStan can prove away.
        /** @var array<int, class-string> $models */
        $models = config('media.usage_models', []);

        if ($models === []) {
            $this->components->warn('No models are registered in config/media.php → usage_models.');

            return self::SUCCESS;
        }

        $stale = MediaUsage::query()->count();
        MediaUsage::query()->delete();

        $written = 0;

        foreach ($models as $model) {
            if (! is_a($model, Model::class, allow_string: true)
                || ! is_a($model, ReferencesMedia::class, allow_string: true)) {
                $this->components->error("{$model} is registered but is not an Eloquent model implementing ReferencesMedia.");

                continue;
            }

            $this->components->task($model, function () use ($model, &$written): void {
                $model::query()->chunkById(200, function (Collection $records) use (&$written): void {
                    foreach ($records as $record) {
                        /** @var Model&ReferencesMedia $record */
                        $record->syncMediaUsage();
                        $written += count(array_filter($record->mediaUsageMap()));
                    }
                });
            });
        }

        $this->components->info("Rebuilt {$written} usage rows (was {$stale}).");

        return self::SUCCESS;
    }
}
