<?php

declare(strict_types=1);

namespace App\Import\Support;

use App\Import\Legacy;
use Illuminate\Support\Str;
use Throwable;

/**
 * Resolves an old WordPress record to the uploads-relative path of its image,
 * read from the legacy dump — the key that turns a dump row into a file in the
 * uploads/uploads backup (used by MediaImporter).
 *
 * Every lookup is guarded: a missing legacy table, key or row returns null
 * rather than throwing, so a partial dump degrades to "no image" instead of a
 * failed import.
 */
final class WpAttachments
{
    /** @var array<int, array{slug: string, path: string}>|null cached attachment index for speaker matching */
    private ?array $attachments = null;

    /** The file an attachment post points at, relative to uploads (e.g. 2024/05/foo.jpg). */
    public function attachmentPath(int $attachmentId): ?string
    {
        try {
            $path = Legacy::postMeta($attachmentId, '_wp_attached_file');
        } catch (Throwable) {
            return null;
        }

        $path = trim((string) $path);

        return $path === '' ? null : $path;
    }

    /** A post's featured image path (via _thumbnail_id → the attachment's file). */
    public function thumbnailPath(int $postId): ?string
    {
        try {
            $thumb = Legacy::postMeta($postId, '_thumbnail_id');
        } catch (Throwable) {
            return null;
        }

        if ($thumb === null || ! ctype_digit(trim($thumb))) {
            return null;
        }

        return $this->attachmentPath((int) $thumb);
    }

    /**
     * A category/term's image path, if the old theme stored one in termmeta —
     * any meta whose key is `thumbnail_id` or mentions thumbnail/image and whose
     * value is a numeric attachment id.
     */
    public function termImagePath(int $termId): ?string
    {
        try {
            $meta = Legacy::table('termmeta')
                ->where('term_id', $termId)
                ->pluck('meta_value', 'meta_key');
        } catch (Throwable) {
            return null;
        }

        foreach ($meta as $key => $value) {
            $key = Str::lower((string) $key);
            $value = trim((string) $value);

            if (! ctype_digit($value)) {
                continue;
            }

            if ($key === 'thumbnail_id' || str_contains($key, 'thumbnail') || str_contains($key, 'image')) {
                $path = $this->attachmentPath((int) $value);

                if ($path !== null) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Best-effort portrait for a speaker, matched by name — the dump keeps no
     * link from a speaker to an image, so this is approximate and conservative.
     *
     * First the `speaker` CPT (if present) whose title slug equals the name's,
     * via its featured image; otherwise an attachment whose own file/title slug
     * contains EVERY word of the speaker's name (so "margie faulk" needs both
     * words, never a single common one). Null when nothing matches.
     */
    public function speakerAttachmentPath(string $name): ?string
    {
        $nameSlug = Str::slug($name);

        if ($nameSlug === '') {
            return null;
        }

        // 1. A speaker CPT post with the same name, via its featured image.
        try {
            $cpt = Legacy::table('posts')
                ->where('post_type', 'speaker')
                ->get(['ID', 'post_title']);

            foreach ($cpt as $post) {
                if (Str::slug((string) $post->post_title) === $nameSlug) {
                    $path = $this->thumbnailPath((int) $post->ID);

                    if ($path !== null) {
                        return $path;
                    }
                }
            }
        } catch (Throwable) {
            // No speaker CPT in this dump — fall through to filename matching.
        }

        // 2. An attachment whose file/title slug contains every word of the name.
        $words = array_values(array_filter(explode('-', $nameSlug), static fn (string $w): bool => mb_strlen($w) >= 2));

        if ($words === []) {
            return null;
        }

        foreach ($this->attachmentIndex() as $attachment) {
            if (self::containsAll($attachment['slug'], $words)) {
                return $attachment['path'];
            }
        }

        return null;
    }

    /**
     * The attachments in the dump as {slug, path}, built once. slug is drawn from
     * both the file name and the attachment title, so either can carry the name.
     *
     * @return array<int, array{slug: string, path: string}>
     */
    private function attachmentIndex(): array
    {
        if ($this->attachments !== null) {
            return $this->attachments;
        }

        try {
            $rows = Legacy::table('posts as p')
                ->join('postmeta as m', 'm.post_id', '=', 'p.ID')
                ->where('p.post_type', 'attachment')
                ->where('m.meta_key', '_wp_attached_file')
                ->get(['p.post_title', 'm.meta_value as path']);
        } catch (Throwable) {
            return $this->attachments = [];
        }

        $index = [];

        foreach ($rows as $row) {
            $path = trim((string) $row->path);

            if ($path === '') {
                continue;
            }

            $index[] = [
                'slug' => Str::slug(pathinfo($path, PATHINFO_FILENAME).' '.(string) $row->post_title),
                'path' => $path,
            ];
        }

        return $this->attachments = $index;
    }

    /** @param  array<int, string>  $words */
    private static function containsAll(string $haystackSlug, array $words): bool
    {
        foreach ($words as $word) {
            if (! str_contains($haystackSlug, $word)) {
                return false;
            }
        }

        return true;
    }
}
