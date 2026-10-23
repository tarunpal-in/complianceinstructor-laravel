<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Enums\Format;
use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Models\ImportMap;
use App\Models\WebinarAsset;

/**
 * Delivery assets, from the variations' WooCommerce downloadable files — Phase 6.
 *
 * Each variation carries the file(s) a buyer receives: a GoToWebinar recording
 * link, a transcript PDF, downloadable materials. These are imported as external
 * URLs on the webinar, one asset per format, so an entitled customer can reach
 * their purchase straight away — the files keep being served by the live site
 * and GoToWebinar through the transition. Copying the WP-hosted files onto the
 * private disk (R2) is the separate step that needs the uploads directory, which
 * is not in the dump; until then the URL is the source of truth.
 *
 * The format is read from the file's name and URL (a recording link or a
 * "RECORDING" name is the recorded format, "TRANSCRIPT" the transcript, and so
 * on). Deduped to one asset per webinar × format — many variations of a webinar
 * point at the same recording.
 */
class AssetImporter extends Importer
{
    public function label(): string
    {
        return 'Assets';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $variations = Legacy::table('posts')
            ->where('post_type', 'product_variation')
            ->whereIn('post_status', ['publish', 'private'])
            ->get(['ID', 'post_parent']);

        foreach ($variations as $variation) {
            $webinarId = ImportMap::localId('webinar', (int) $variation->post_parent);

            if ($webinarId === null) {
                continue;
            }

            $files = $this->parseFiles(Legacy::postMeta((int) $variation->ID, '_downloadable_files'));

            if ($files === []) {
                $result->skipped();

                continue;
            }

            foreach ($files as [$name, $url]) {
                $format = $this->inferFormat($name, $url);

                // One asset per webinar × format; the key dedupes across the many
                // variations that share a recording.
                $key = (int) sprintf('%u', crc32($webinarId.'-'.$format->value));

                $this->sync($result, 'webinar_asset', $key, WebinarAsset::class, function (WebinarAsset $asset) use ($webinarId, $format, $url): void {
                    $asset->webinar_id = $webinarId;
                    $asset->format = $format;
                    $asset->url = $url;
                    $asset->disk_path = null;
                    // External links (GoToWebinar, the live site's PDFs) open out
                    // rather than embedding — the Phase-1 delivery choice.
                    $asset->embed = false;
                    $asset->available_from = null;
                    $asset->is_active = true;
                });
            }
        }

        return $result;
    }

    /**
     * Unpack WooCommerce's serialized downloadable-files map into [name, url].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function parseFiles(?string $serialized): array
    {
        if ($serialized === null || $serialized === '' || $serialized === 'a:0:{}') {
            return [];
        }

        $data = @unserialize($serialized, ['allowed_classes' => false]);

        if (! is_array($data)) {
            return [];
        }

        $files = [];

        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $url = trim((string) ($entry['file'] ?? ''));

            if ($url === '') {
                continue;
            }

            $files[] = [trim((string) ($entry['name'] ?? '')), $url];
        }

        return $files;
    }

    private function inferFormat(string $name, string $url): Format
    {
        $n = strtoupper($name);
        $u = strtolower($url);

        return match (true) {
            str_contains($u, 'gotowebinar'), str_contains($u, 'youtube'), str_contains($u, 'vimeo'), str_contains($u, 'zoom'), str_contains($n, 'RECORD') => Format::Recorded,
            str_contains($n, 'TRANSCRIPT') => Format::Transcript,
            str_contains($n, 'DOWNLOAD'), str_contains($n, 'MATERIAL'), str_contains($n, 'SLIDE'), str_contains($n, 'HANDOUT'), str_contains($n, 'PPT'), str_contains($n, 'EXCEL') => Format::Download,
            str_ends_with($u, '.pdf') => Format::Transcript,
            default => Format::Recorded,
        };
    }
}
