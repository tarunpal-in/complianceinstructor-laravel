<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Models\Industry;
use Illuminate\Support\Str;

/**
 * Industries, from the real `product_cat` terms.
 *
 * WooCommerce mixes two things in one taxonomy: genuine industries (Human
 * Resources, Payroll, Food Safety…) and format/housekeeping categories
 * (RECORDED, LIVE WEBINARS, PACKAGES, Uncategorized). Only the former are
 * industries in the new model — §5.3 keeps format on the offering, not here — so
 * the housekeeping slugs are skipped. Names arrive upper-cased and HTML-encoded
 * ("HOUSING &amp; CONSTRUCTION"); they are decoded and title-cased for display,
 * and the WordPress slug is kept so old category URLs can 301 later.
 */
class IndustryImporter extends Importer
{
    /** product_cat slugs that are format or housekeeping, not an industry. */
    private const SKIP_SLUGS = ['recorded', 'live', 'packages', 'uncategorized', 'training', 'industry'];

    public function label(): string
    {
        return 'Industries';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $terms = Legacy::table('term_taxonomy as tt')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'product_cat')
            ->whereNotIn('t.slug', self::SKIP_SLUGS)
            ->select('t.term_id', 't.name', 't.slug', 'tt.count')
            ->orderByDesc('tt.count')
            ->get();

        foreach ($terms as $term) {
            $name = Str::title(Str::lower(html_entity_decode(trim((string) $term->name), ENT_QUOTES | ENT_HTML5)));

            if ($name === '') {
                $result->skipped();

                continue;
            }

            $this->sync($result, 'industry', (int) $term->term_id, Industry::class, function (Industry $industry) use ($name, $term): void {
                $industry->name = $name;
                $industry->is_active = true;

                if (! $industry->exists) {
                    $base = (string) ($term->slug ?: Str::slug($name));
                    $industry->slug = $this->uniqueSlug(Industry::class, $base);
                }
            });
        }

        return $result;
    }
}
