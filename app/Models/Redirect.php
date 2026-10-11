<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RedirectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One "this URL moved to that one" — see the migration.
 *
 * The resolution logic lives here because two callers need it and must agree:
 * the middleware that serves the 301, and the save guards that refuse to create
 * a chain or a loop (client SEO brief §4). `destinationFor()` follows a chain to
 * its end and returns a single hop, so even data that somehow contains a chain
 * never makes a visitor bounce more than once.
 *
 * @property string $from_path
 * @property string $to_path
 * @property int $status_code
 * @property bool $is_active
 * @property string $source
 * @property int $hits
 * @property \Illuminate\Support\Carbon|null $last_hit_at
 */
class Redirect extends Model
{
    /** @use HasFactory<RedirectFactory> */
    use HasFactory;

    /** A chain longer than this is treated as a loop and refused service. */
    private const MAX_HOPS = 15;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SLUG_CHANGE = 'slug_change';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = [
        'from_path', 'to_path', 'status_code', 'is_active', 'source', 'note',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status_code' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    /**
     * One normalised form for a path, so a stored key and a request can match.
     *
     * Leading slash, no trailing slash, lowercased, query dropped. A request for
     * `/Old-Page/?ref=x` and a stored `/old-page` are the same move.
     */
    public static function normalise(string $path): string
    {
        $path = (string) (parse_url(trim($path), PHP_URL_PATH) ?: $path);
        $path = '/'.trim($path, '/');

        return $path === '/' ? '/' : Str::lower($path);
    }

    /**
     * Where a request for this path should go, following any chain to the end.
     *
     * Returns `[to_path, status_code]` or null for no redirect. Capped at
     * MAX_HOPS with loop detection, so a cycle in the data resolves to null
     * rather than spinning — the save guards stop cycles being created, and this
     * is the belt to that braces.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function destinationFor(string $path): ?array
    {
        $from = self::normalise($path);
        $seen = [];
        $destination = null;
        $status = 301;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if (isset($seen[$from])) {
                return null; // a loop — refuse rather than spin
            }
            $seen[$from] = true;

            $redirect = self::query()
                ->where('is_active', true)
                ->where('from_path', $from)
                ->first();

            if ($redirect === null) {
                // No further hop: the last destination we found is the final one
                // (null if we never moved at all).
                return $destination === null ? null : [$destination, $status];
            }

            $destination = $redirect->to_path;
            $status = $redirect->status_code;

            // An external or absolute target is the end of the line.
            if (! str_starts_with($destination, '/')) {
                return [$destination, $status];
            }

            $from = self::normalise($destination);
        }

        return null; // too many hops — treat as a loop
    }

    /** An internal target normalised like a from_path; an external one untouched. */
    public static function normaliseTarget(string $to): string
    {
        return str_starts_with(trim($to), '/') ? self::normalise($to) : trim($to);
    }

    /**
     * Record that a path has moved, keeping the register free of chains and loops.
     *
     * Used by the slug-change hook, where a chain can arise honestly: an article
     * renamed twice would leave old-1 → old-2 → new. So before writing old → new
     * it drops any redirect out of the new path (a slug changed back), and
     * repoints anything that led to the old path straight at the new one. The
     * result is always one hop from any source to the final URL.
     */
    public static function recordMove(string $from, string $to, string $source = self::SOURCE_SLUG_CHANGE): void
    {
        $from = self::normalise($from);
        $to = self::normaliseTarget($to);

        if ($from === self::normaliseTarget($to)) {
            return;
        }

        // The destination must not itself redirect, or from → to → … is a chain.
        self::query()->where('from_path', self::normalise($to))->delete();

        // Flatten: whatever pointed at the old path now points at the new one.
        self::query()->where('to_path', $from)->update(['to_path' => $to]);

        self::query()->updateOrCreate(
            ['from_path' => $from],
            ['to_path' => $to, 'status_code' => 301, 'is_active' => true, 'source' => $source],
        );
    }

    /**
     * Whether a redirect from this path would hijack a live page.
     *
     * The redirect middleware runs before routing, so a redirect whose source is
     * a URL that currently shows content would send visitors away from that
     * content instead of to it — the opposite of the point. This is checked when
     * a redirect is created by hand or imported; a slug-change redirect is safe
     * by construction, since the old slug is no longer live by the time it runs.
     */
    public static function shadowsLiveContent(string $from): bool
    {
        $from = self::normalise($from);

        if ($from === '/') {
            return true; // the homepage is always live
        }

        $segments = explode('/', trim($from, '/'));

        if ($segments[0] === 'webinars' && isset($segments[1]) && count($segments) === 2) {
            return Webinar::query()->public()->where('slug', $segments[1])->exists();
        }

        if ($segments[0] === 'blog' && isset($segments[1]) && count($segments) === 2) {
            return Post::query()->live()->where('slug', $segments[1])->exists();
        }

        if (count($segments) === 1) {
            return Page::query()->live()->where('slug', $segments[0])->exists();
        }

        return false;
    }

    /** Record that this redirect was used, cheaply. */
    public static function recordHit(string $fromPath): void
    {
        self::query()
            ->where('from_path', self::normalise($fromPath))
            ->update([
                'hits' => \Illuminate\Support\Facades\DB::raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
    }

    /**
     * Whether making `$from` redirect to `$to` would create a chain or a loop.
     *
     * A loop is `$to` resolving back to `$from`; a chain is `$to` being the
     * `from_path` of another active redirect. Both are rejected at save, which is
     * why the middleware almost never has to fall back on its hop cap.
     *
     * Returns a reason to show the editor, or null if the redirect is safe.
     */
    public static function problemWith(string $from, string $to, ?int $ignoreId = null): ?string
    {
        $from = self::normalise($from);

        if (! str_starts_with($to, '/')) {
            // An external target cannot chain or loop within this table.
            return null;
        }

        $toNorm = self::normalise($to);

        if ($toNorm === $from) {
            return 'A redirect cannot point at itself.';
        }

        // Follow the destination forward. If it leads back to the source, adding
        // this redirect would close a loop — the more specific problem, so it is
        // checked before a plain chain.
        $cursor = $toNorm;
        $seen = [$from => true];

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if (isset($seen[$cursor])) {
                return 'This would create a redirect loop.';
            }
            $seen[$cursor] = true;

            $step = self::query()
                ->where('is_active', true)
                ->where('from_path', $cursor)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->first();

            if ($step === null || ! str_starts_with($step->to_path, '/')) {
                break;
            }

            $cursor = self::normalise($step->to_path);
        }

        // Not a loop, but if the destination already moves somewhere then
        // from → to → … is a chain; point from straight at the end instead.
        $onward = self::query()
            ->where('is_active', true)
            ->where('from_path', $toNorm)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();

        if ($onward !== null) {
            return "That destination already redirects to {$onward->to_path}. Point this straight there to avoid a chain.";
        }

        return null;
    }
}
