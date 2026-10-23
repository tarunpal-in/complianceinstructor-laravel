<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Models\ImportMap;
use App\Models\SeoMeta;
use App\Models\Webinar;

/**
 * SEO overrides for the migrated webinars, from Yoast — Phase 5.
 *
 * Only real, hand-written values move: Yoast's `_yoast_wpseo_title` and
 * `_yoast_wpseo_metadesc` where the client wrote their own (all 200 in this
 * store are plain text, no `%%template%%` tokens — any that slip through with a
 * token are dropped so a placeholder never reaches a page). Attaches through the
 * webinar's own `seo()` relation, so a webinar with no override keeps the
 * system-generated default. Idempotent — one SEO row per webinar.
 */
class SeoImporter extends Importer
{
    public function label(): string
    {
        return 'SEO meta';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $maps = ImportMap::query()->where('legacy_type', 'webinar')->get(['legacy_id', 'model_id']);

        foreach ($maps as $map) {
            $webinar = Webinar::query()->find($map->model_id);

            if ($webinar === null) {
                continue;
            }

            $meta = Legacy::postMetaMap((int) $map->legacy_id);

            $title = $this->clean($meta['_yoast_wpseo_title'] ?? '');
            $description = $this->clean($meta['_yoast_wpseo_metadesc'] ?? '');
            $keywords = trim((string) ($meta['_yoast_wpseo_focuskw'] ?? ''));
            $canonical = trim((string) ($meta['_yoast_wpseo_canonical'] ?? ''));
            $noindex = (string) ($meta['_yoast_wpseo_meta-robots-noindex'] ?? '') === '1';

            if ($title === '' && $description === '' && $keywords === '' && $canonical === '' && ! $noindex) {
                $result->skipped();

                continue;
            }

            /** @var SeoMeta $seo */
            $seo = $webinar->seo()->firstOrNew([]);
            $isNew = ! $seo->exists;

            $seo->fill([
                'title' => $title !== '' ? $title : null,
                'description' => $description !== '' ? $description : null,
                'keywords' => $keywords !== '' ? $keywords : null,
                'canonical' => $canonical !== '' ? $canonical : null,
                'robots' => $noindex ? 'noindex,follow' : null,
            ]);
            $webinar->seo()->save($seo);

            $isNew ? $result->created() : $result->updated();
        }

        return $result;
    }

    /** Trim, and drop any value that still carries a Yoast template token. */
    private function clean(mixed $value): string
    {
        $value = trim((string) $value);

        return str_contains($value, '%%') ? '' : $value;
    }
}
