<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Media library
|--------------------------------------------------------------------------
|
| Our settings, distinct from config/media-library.php, which belongs to
| spatie. See docs/media-manager.md.
|
*/

return [

    /*
     * Where library files land. Marketing assets only — anything a customer
     * paid for lives on the private disk behind an entitlement check (§9), and
     * never appears in a browsable library at all.
     */
    'disk' => env('MEDIA_DISK', 'public'),

    /*
     * The disk for library files marked private. Reachable only through the
     * signed route, which takes a media id and resolves the path server-side —
     * §14 #11 bans URL proxies, so it never accepts a path.
     */
    'private_disk' => env('MEDIA_PRIVATE_DISK', 'local'),

    /*
     * Upload ceiling in kilobytes. 128 MB covers a large slide deck and a
     * scanned manual. Video is out of scope (docs/media-manager.md §2), so
     * there is no case for going higher — and the server must agree:
     * upload_max_filesize, post_max_size and nginx client_max_body_size.
     */
    'max_upload_kb' => (int) env('MEDIA_MAX_UPLOAD_KB', 131072),

    /*
     * How long a signed URL for a private file stays valid.
     */
    'signed_url_minutes' => (int) env('MEDIA_SIGNED_URL_MINUTES', 10),

    /*
     * Models that reference library media and therefore write `media_usages`.
     *
     * `media:reindex-usages` walks these to rebuild the index from scratch.
     * Each must use the RecordsMediaUsage trait. Pages and page sections join
     * this list at P6, which is when reuse — and so the delete guard — starts
     * doing most of its work.
     */
    'usage_models' => [
        App\Models\Setting::class,
        // App\Models\Page::class,      — P6
        // App\Models\PageSection::class, — P6
    ],

];
