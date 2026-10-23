<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One legacy record ↔ the model it became — the migration backbone.
 *
 * Every importer writes a row here, so a re-run finds the existing mapping and
 * updates in place (idempotent, like the ledger), and a later phase resolves a
 * foreign key by asking "what did legacy order 10862 become?" rather than
 * guessing from ids that no longer line up.
 *
 * @property string $source
 * @property string $legacy_type
 * @property int $legacy_id
 * @property string $model_type
 * @property int $model_id
 * @property string|null $content_hash
 */
class ImportMap extends Model
{
    protected $fillable = [
        'source', 'legacy_type', 'legacy_id', 'model_type', 'model_id', 'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
            'model_id' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Record (or refresh) the mapping from a legacy id to a model, idempotently.
     *
     * The unique key is (source, legacy_type, legacy_id), so the same source row
     * always points at the same model row no matter how often the import runs.
     */
    public static function remember(
        string $legacyType,
        int $legacyId,
        Model $model,
        ?string $contentHash = null,
        string $source = 'wp',
    ): self {
        /** @var self $map */
        $map = self::query()->updateOrCreate(
            ['source' => $source, 'legacy_type' => $legacyType, 'legacy_id' => $legacyId],
            [
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
                'content_hash' => $contentHash,
            ],
        );

        return $map;
    }

    /**
     * The local id a legacy record became, or null if it was never imported.
     */
    public static function localId(string $legacyType, int $legacyId, string $source = 'wp'): ?int
    {
        return self::query()
            ->where('source', $source)
            ->where('legacy_type', $legacyType)
            ->where('legacy_id', $legacyId)
            ->value('model_id');
    }

    /**
     * Whether this legacy record has already been imported with this exact
     * content — the signal to skip it on a re-run.
     */
    public static function isUnchanged(string $legacyType, int $legacyId, string $contentHash, string $source = 'wp'): bool
    {
        return self::query()
            ->where('source', $source)
            ->where('legacy_type', $legacyType)
            ->where('legacy_id', $legacyId)
            ->where('content_hash', $contentHash)
            ->exists();
    }
}
