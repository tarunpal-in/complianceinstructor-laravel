<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Support\WpAttachments;
use App\Models\ImportMap;
use App\Models\Industry;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Speaker;
use App\Models\Webinar;
use App\Models\WebinarSection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 6c of the migration — attach the old WordPress images to the already-
 * imported records, from the local `uploads` backup disk.
 *
 * Four sources, each resolved through WpAttachments + the dump's attachment
 * metadata, matched to a file in uploads/uploads:
 *   - webinars  : the product's featured image → the webinar's card AND banner.
 *   - industries: the category's termmeta image → the industry's `card`.
 *   - content   : <img> URLs embedded in a webinar's description, repointed from
 *                 the dead wp-content URL to a freshly imported library file.
 *   - speakers  : a best-effort name match (no reliable link exists).
 *
 * **The backup is never moved or deleted.** Library files are added from a temp
 * copy (addMedia moves the temp, not the backup); model collections use
 * `preservingOriginal()` (which copies). Re-runnable: a library file is deduped
 * by sha256, a model collection is skipped when already filled, and a rewritten
 * <img> no longer matches wp-content.
 */
class MediaImporter
{
    private const DISK = 'uploads';

    private WpAttachments $wp;

    private ?MediaFolder $folder = null;

    public function __construct()
    {
        $this->wp = new WpAttachments;
    }

    /**
     * @param  list<string>  $only  empty = all sections
     * @return array<string, array<string, int>>
     */
    public function run(array $only, bool $dryRun, int $limit): array
    {
        $sections = $only !== [] ? $only : ['webinars', 'industries', 'content', 'speakers'];
        $stats = [];

        if (in_array('webinars', $sections, true)) {
            $stats['webinars'] = $this->webinars($dryRun, $limit);
        }
        if (in_array('industries', $sections, true)) {
            $stats['industries'] = $this->industries($dryRun, $limit);
        }
        if (in_array('content', $sections, true)) {
            $stats['content'] = $this->content($dryRun, $limit);
        }
        if (in_array('speakers', $sections, true)) {
            $stats['speakers'] = $this->speakers($dryRun, $limit);
        }

        return $stats;
    }

    /** @return array<string, int> */
    private function webinars(bool $dryRun, int $limit): array
    {
        $stats = ['matched' => 0, 'missing' => 0, 'skipped' => 0];
        $done = 0;

        foreach ($this->maps('webinar') as $map) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            $webinar = Webinar::query()->find($map->model_id);
            if ($webinar === null) {
                continue;
            }

            $rel = $this->wp->thumbnailPath((int) $map->legacy_id);
            if ($rel === null || ! $this->exists($rel)) {
                $stats['missing']++;

                continue;
            }

            $done++;
            $hash = $this->hashOf($rel);

            // Already set to this exact image on both slots — nothing to do.
            if ($webinar->card_media_id !== null && $webinar->card_media_id === $webinar->banner_media_id
                && Media::query()->whereKey($webinar->card_media_id)->where('hash', $hash)->exists()) {
                $stats['skipped']++;

                continue;
            }

            if ($dryRun) {
                $stats['matched']++;

                continue;
            }

            $media = $this->toLibrary($rel, $hash);
            $webinar->forceFill(['card_media_id' => $media->getKey(), 'banner_media_id' => $media->getKey()])->save();
            $stats['matched']++;
        }

        return $stats;
    }

    /** @return array<string, int> */
    private function industries(bool $dryRun, int $limit): array
    {
        $stats = ['matched' => 0, 'missing' => 0, 'skipped' => 0];
        $done = 0;

        foreach ($this->maps('industry') as $map) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            $industry = Industry::query()->find($map->model_id);
            if ($industry === null) {
                continue;
            }

            if ($industry->hasMedia('card')) {
                $stats['skipped']++;

                continue;
            }

            $rel = $this->wp->termImagePath((int) $map->legacy_id);
            if ($rel === null || ! $this->exists($rel)) {
                $stats['missing']++;

                continue;
            }

            $done++;

            if (! $dryRun) {
                $industry->addMedia($this->absolute($rel))
                    ->preservingOriginal()
                    ->usingFileName($this->fileName($rel))
                    ->toMediaCollection('card');
            }

            $stats['matched']++;
        }

        return $stats;
    }

    /** @return array<string, int> */
    private function content(bool $dryRun, int $limit): array
    {
        $stats = ['sections' => 0, 'rewritten' => 0];
        $done = 0;

        $sections = WebinarSection::query()
            ->where('body', 'like', '%wp-content/uploads/%')
            ->get();

        foreach ($sections as $section) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            $original = (string) $section->body;
            $count = 0;
            $new = $this->rewriteImages($original, $dryRun, $count);

            if ($count === 0) {
                continue;
            }

            $done++;
            $stats['sections']++;
            $stats['rewritten'] += $count;

            if (! $dryRun && $new !== $original) {
                $section->body = $new;
                $section->save();
            }
        }

        return $stats;
    }

    /** @return array<string, int> */
    private function speakers(bool $dryRun, int $limit): array
    {
        $stats = ['matched' => 0, 'total' => 0];
        $done = 0;

        foreach (Speaker::query()->cursor() as $speaker) {
            $stats['total']++;

            if ($limit > 0 && $done >= $limit) {
                continue;
            }

            if ($speaker->hasMedia('portrait')) {
                continue;
            }

            $rel = $this->wp->speakerAttachmentPath((string) $speaker->name);
            if ($rel === null || ! $this->exists($rel)) {
                continue;
            }

            $done++;
            $stats['matched']++;

            if (! $dryRun) {
                $speaker->addMedia($this->absolute($rel))
                    ->preservingOriginal()
                    ->usingFileName($this->fileName($rel))
                    ->toMediaCollection('portrait');
            }
        }

        return $stats;
    }

    /**
     * Repoint every wp-content/uploads <img> whose file is in the backup to a
     * freshly imported library file; leave the rest untouched. `$count` is the
     * number repointed (counted in dry-run too, without writing anything).
     */
    private function rewriteImages(string $html, bool $dryRun, int &$count): string
    {
        return (string) preg_replace_callback(
            '/src\s*=\s*(["\'])(?<url>[^"\']*wp-content\/uploads\/[^"\']+)\1/i',
            function (array $m) use ($dryRun, &$count): string {
                $url = html_entity_decode($m['url']);
                $pos = strpos($url, 'wp-content/uploads/');
                $rel = $pos === false ? null : substr($url, $pos + strlen('wp-content/uploads/'));
                $rel = $rel === null ? null : rawurldecode(explode('?', $rel)[0]);

                if ($rel === null || ! $this->exists($rel)) {
                    return $m[0]; // not in the backup — leave the original src
                }

                $count++;

                if ($dryRun) {
                    return $m[0];
                }

                $media = $this->toLibrary($rel, $this->hashOf($rel));
                $newUrl = $media->detailImageUrl() ?? $media->getUrl();

                return 'src='.$m[1].$newUrl.$m[1];
            },
            $html,
        );
    }

    // Helpers -----------------------------------------------------------------

    /** Add a backup file to the library, deduped by hash, without moving the backup. */
    private function toLibrary(string $rel, string $hash): Media
    {
        /** @var Media|null $existing */
        $existing = Media::query()
            ->where('hash', $hash)
            ->where('model_type', MediaFolder::class)
            ->where('collection_name', Media::LIBRARY_COLLECTION)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Spatie's addMedia MOVES its source, so hand it a throwaway copy — the
        // backup file under uploads/ is never touched.
        $ext = Str::lower(pathinfo($rel, PATHINFO_EXTENSION));
        $temp = (string) tempnam(sys_get_temp_dir(), 'wpmedia').'.'.$ext;
        copy($this->absolute($rel), $temp);

        $name = pathinfo($rel, PATHINFO_FILENAME);

        /** @var Media $media */
        $media = $this->folder()
            ->addMedia($temp)
            ->usingName(Str::of($name)->replace(['-', '_'], ' ')->squish()->title()->value())
            ->usingFileName($this->fileName($rel))
            ->withCustomProperties(['alt' => '', 'caption' => '', 'imported' => true])
            ->toMediaCollection(Media::LIBRARY_COLLECTION);

        $media->forceFill(['hash' => $hash])->save();

        return $media;
    }

    private function folder(): MediaFolder
    {
        return $this->folder ??= MediaFolder::query()->firstOrCreate(
            ['slug' => 'imported'],
            ['name' => 'Imported', 'sort_order' => 0],
        );
    }

    /** @return \Illuminate\Support\Collection<int, ImportMap> */
    private function maps(string $legacyType): \Illuminate\Support\Collection
    {
        return ImportMap::query()
            ->where('source', 'wp')
            ->where('legacy_type', $legacyType)
            ->get();
    }

    private function exists(string $rel): bool
    {
        return Storage::disk(self::DISK)->exists($rel);
    }

    private function absolute(string $rel): string
    {
        return Storage::disk(self::DISK)->path($rel);
    }

    private function hashOf(string $rel): string
    {
        return (string) hash_file('sha256', $this->absolute($rel));
    }

    private function fileName(string $rel): string
    {
        return Str::slug(pathinfo($rel, PATHINFO_FILENAME)).'.'.Str::lower(pathinfo($rel, PATHINFO_EXTENSION));
    }
}
