<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\MediaUsage;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps `media_usages` in step with a record that references library media.
 *
 * Rebuilt on every save rather than patched: the record declares which media it
 * currently points at, this deletes its old rows and writes the current set. The
 * index therefore cannot drift, which is what the delete guard depends on —
 * a guard that trusts a stale index is worse than no guard, because it says
 * "safe to delete" with authority.
 *
 * Implement App\Contracts\ReferencesMedia alongside it; `mediaUsageMap()` comes
 * from there rather than being declared abstract here.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements \App\Contracts\ReferencesMedia
 */
trait RecordsMediaUsage
{
    public static function bootRecordsMediaUsage(): void
    {
        static::saved(static function (Model $model): void {
            /** @var static $model */
            $model->syncMediaUsage();
        });

        // Usage rows are meaningless once the record is gone, and leaving them
        // would make the delete guard refuse to remove a file whose only user
        // no longer exists.
        static::deleted(static function (Model $model): void {
            /** @var static $model */
            $model->clearMediaUsage();
        });
    }

    public function syncMediaUsage(): void
    {
        $this->clearMediaUsage();

        foreach ($this->mediaUsageMap() as $context => $mediaId) {
            if ($mediaId === null) {
                continue;
            }

            MediaUsage::query()->updateOrCreate([
                'media_id' => $mediaId,
                'usable_type' => $this->getMorphClass(),
                'usable_id' => $this->getKey(),
                'context' => $context,
            ]);
        }
    }

    public function clearMediaUsage(): void
    {
        MediaUsage::query()
            ->where('usable_type', $this->getMorphClass())
            ->where('usable_id', $this->getKey())
            ->delete();
    }
}
