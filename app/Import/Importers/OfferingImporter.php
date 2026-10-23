<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Import\Maps\AddonFormats;
use App\Import\Support\LegacyMoney;
use App\Models\ImportMap;
use App\Models\Offering;
use App\Models\Webinar;

/**
 * Offerings, from the WooCommerce `product_variation` posts.
 *
 * Each variation is one sellable option, so it becomes one offering — the combo
 * is preserved, not flattened: a "Live + Recorded" variation is a single
 * offering that grants both formats (§5.3's "an offering grants one or more
 * formats"). Price is the variation's own price in cents; the format set comes
 * from its `pa_addons` term. Runs after WebinarImporter, resolving each
 * variation's parent product to the webinar it became through the import map.
 */
class OfferingImporter extends Importer
{
    public function label(): string
    {
        return 'Offerings';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $webinarMorph = (new Webinar)->getMorphClass();

        $variations = Legacy::table('posts')
            ->where('post_type', 'product_variation')
            ->whereIn('post_status', ['publish', 'private'])
            ->get();

        foreach ($variations as $variation) {
            $variationId = (int) $variation->ID;
            $webinarId = ImportMap::localId('webinar', (int) $variation->post_parent);

            if ($webinarId === null) {
                // Parent product was not imported (outside the status set).
                $result->skipped();

                continue;
            }

            $addon = (string) Legacy::postMeta($variationId, 'attribute_pa_addons');

            if ($addon === '' || ! AddonFormats::knows($addon)) {
                $result->failed("Variation {$variationId}: unknown add-on '{$addon}'.");

                continue;
            }

            $formats = AddonFormats::forSlug($addon);
            $priceCents = LegacyMoney::toCents(
                Legacy::postMeta($variationId, '_price') ?? Legacy::postMeta($variationId, '_regular_price'),
            );
            $isActive = $variation->post_status === 'publish';
            $sortOrder = (int) ($variation->menu_order ?? 0);

            $offering = $this->sync($result, 'offering', $variationId, Offering::class, function (Offering $offering) use ($webinarMorph, $webinarId, $priceCents, $isActive, $sortOrder, $variationId): void {
                $offering->offerable_type = $webinarMorph;
                $offering->offerable_id = $webinarId;
                $offering->price_cents = $priceCents;
                $offering->currency = 'USD';
                $offering->seats_per_unit = 1;
                $offering->is_active = $isActive;
                $offering->sort_order = $sortOrder;

                if (! $offering->exists) {
                    $offering->sku = 'WC-'.$variationId;
                }
            });

            // Re-derive the format rows each run so a changed combo stays correct.
            $offering->formats()->delete();

            foreach ($formats as $format) {
                $offering->formats()->create(['format' => $format]);
            }
        }

        return $result;
    }
}
