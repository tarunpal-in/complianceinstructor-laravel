<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Models\ImportMap;
use App\Models\Redirect;
use App\Models\Webinar;

/**
 * 301s from the old WooCommerce product URLs to the new webinar pages — Phase 5.
 *
 * The live store serves each webinar at `/product/{slug}` (its `woocommerce_permalinks`
 * product base); the new site serves it at `/webinars/{slug}`. Preserving these
 * is the whole point of keeping the WordPress slug in Phase 1 — the catalogue is
 * this business's search traffic, and a 301 is what keeps the ranking. The old
 * path cannot shadow live content (nothing new answers on `/product/*`), and the
 * register is kept flat and idempotent by `from_path`.
 */
class RedirectImporter extends Importer
{
    public function label(): string
    {
        return 'Redirects';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $maps = ImportMap::query()->where('legacy_type', 'webinar')->get(['legacy_id', 'model_id']);

        // The old product slug for each legacy product, in one query.
        $slugs = Legacy::table('posts')
            ->whereIn('ID', $maps->pluck('legacy_id')->all())
            ->pluck('post_name', 'ID');

        foreach ($maps as $map) {
            $webinar = Webinar::query()->find($map->model_id);
            $oldSlug = (string) ($slugs[$map->legacy_id] ?? '');

            if ($webinar === null || $oldSlug === '') {
                $result->skipped();

                continue;
            }

            $from = Redirect::normalise('/product/'.$oldSlug);
            $to = '/webinars/'.$webinar->slug;

            if ($from === Redirect::normaliseTarget($to)) {
                $result->skipped();

                continue;
            }

            $redirect = Redirect::query()->updateOrCreate(
                ['from_path' => $from],
                ['to_path' => $to, 'status_code' => 301, 'is_active' => true, 'source' => Redirect::SOURCE_IMPORT],
            );

            $redirect->wasRecentlyCreated ? $result->created() : $result->updated();
        }

        return $result;
    }
}
