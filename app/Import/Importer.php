<?php

declare(strict_types=1);

namespace App\Import;

use App\Models\ImportMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The shape every phase importer shares.
 *
 * Each knows how to read one kind of legacy record and turn it into app models,
 * returning an ImportResult. The base carries the two things they all need: an
 * idempotent upsert keyed through `import_maps` (so a re-run updates rather than
 * duplicates, and dry-run writes nothing), and unique-slug generation.
 */
abstract class Importer
{
    /** A human name for the report. */
    abstract public function label(): string;

    abstract public function run(): ImportResult;

    /**
     * Create or update the model a legacy record maps to, tracking the outcome.
     *
     * `$fill` receives a fresh-or-existing model and sets its attributes; it can
     * read `$model->exists` to do new-only work (slug, defaults). The write is
     * always made; dry-run is the orchestrator's job — it runs every importer
     * inside one transaction and rolls it back, which keeps foreign keys
     * resolvable between phases while persisting nothing.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  callable(TModel): void  $fill
     * @return TModel
     */
    protected function sync(
        ImportResult $result,
        string $legacyType,
        int $legacyId,
        string $modelClass,
        callable $fill,
    ): Model {
        $existingId = ImportMap::localId($legacyType, $legacyId);

        /** @var TModel|null $model */
        $model = $existingId !== null ? $modelClass::query()->find($existingId) : null;
        $isNew = $model === null;

        if ($model === null) {
            /** @var TModel $model */
            $model = new $modelClass;
        }

        $fill($model);
        $model->save();
        ImportMap::remember($legacyType, $legacyId, $model);

        $isNew ? $result->created() : $result->updated();

        return $model;
    }

    /**
     * A slug not yet taken on the model's table (soft-deleted rows included, so
     * a restore never collides). `$base` is already slugged.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function uniqueSlug(string $modelClass, string $base, ?int $ignoreId = null): string
    {
        $base = $base !== '' ? $base : 'item';
        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($modelClass, $slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /** @param  class-string<Model>  $modelClass */
    private function slugTaken(string $modelClass, string $slug, ?int $ignoreId): bool
    {
        $query = $modelClass::query();

        // Soft-deleted rows still own their slug, so a later restore never
        // collides with one the import handed out.
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $query->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
