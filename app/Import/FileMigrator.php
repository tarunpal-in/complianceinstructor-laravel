<?php

declare(strict_types=1);

namespace App\Import;

use App\Models\WebinarAsset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies a webinar's WP-hosted delivery file onto the private disk — Phase 6b.
 *
 * Phase 6 imported these as external URLs on the live site; this fetches each
 * one and stores it on the webinar-assets disk (R2 in production), then points
 * the asset at the stored copy and drops the external URL, so delivery no longer
 * depends on the old WordPress site staying up and §9's signed, private serving
 * applies. Only WP-hosted files are moved — a GoToWebinar recording lives on
 * that platform and cannot be fetched here, so it is left as a URL.
 *
 * Idempotent: an asset already on the disk, or one with no movable file, is
 * skipped. The stored path is keyed by the source URL, so a re-run never writes
 * a second copy.
 */
class FileMigrator
{
    public function __construct(private readonly string $diskName) {}

    /** Whether this asset has a file that can be fetched and moved. */
    public static function isMovable(WebinarAsset $asset): bool
    {
        $url = (string) $asset->url;

        return $asset->disk_path === null && $url !== '' && str_contains($url, 'wp-content');
    }

    /**
     * Move one asset's file. Returns 'downloaded', 'skipped' or 'failed'.
     */
    public function migrate(WebinarAsset $asset): string
    {
        if (! self::isMovable($asset)) {
            return 'skipped';
        }

        $url = (string) $asset->url;

        try {
            // withoutVerifying: this fetches the client's own public files from
            // their own domain in a one-off migration, and dev PHP (XAMPP) often
            // ships without a CA bundle, which would otherwise fail every fetch.
            // The files are non-sensitive and the source is known.
            $response = Http::withoutVerifying()->timeout(120)->get($url);
        } catch (Throwable) {
            return 'failed';
        }

        if (! $response->successful()) {
            return 'failed';
        }

        $extension = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'bin';
        $path = 'webinar-assets/migrated/'.sha1($url).'.'.$extension;

        Storage::disk($this->diskName)->put($path, $response->body());

        $asset->forceFill(['disk_path' => $path, 'url' => null])->save();

        return 'downloaded';
    }
}
