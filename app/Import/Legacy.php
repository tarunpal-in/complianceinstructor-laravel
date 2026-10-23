<?php

declare(strict_types=1);

namespace App\Import;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one door to the old WordPress database (read-only).
 *
 * Every importer reaches the source through here, so the connection name and
 * the `wp_` prefix live in a single place. The prefix is set on the connection
 * (config/database.php), so `Legacy::table('posts')` queries `wp_posts`.
 */
final class Legacy
{
    public const CONNECTION = 'legacy';

    /** Whether the migration source is configured on this environment. */
    public static function configured(): bool
    {
        return filled(config('database.connections.'.self::CONNECTION.'.database'));
    }

    public static function connection(): Connection
    {
        if (! self::configured()) {
            throw new RuntimeException(
                'The legacy database is not configured. Set LEGACY_DB_DATABASE (and credentials) '
                .'and load the WordPress dump before running the import.',
            );
        }

        /** @var Connection $connection */
        $connection = DB::connection(self::CONNECTION);

        return $connection;
    }

    /** A query builder on a WordPress table, named without the `wp_` prefix. */
    public static function table(string $table): Builder
    {
        return self::connection()->table($table);
    }

    /**
     * One postmeta value for a post, decoded from the key/value store WordPress
     * keeps everything in. Null when the key is absent.
     */
    public static function postMeta(int $postId, string $key): ?string
    {
        $value = self::table('postmeta')
            ->where('post_id', $postId)
            ->where('meta_key', $key)
            ->value('meta_value');

        return $value === null ? null : (string) $value;
    }

    /**
     * All of a post's meta as a flat key => value map, for when a row needs
     * many fields at once (a product's ACF set, an order's billing block).
     *
     * @return array<string, string>
     */
    public static function postMetaMap(int $postId): array
    {
        return self::table('postmeta')
            ->where('post_id', $postId)
            ->pluck('meta_value', 'meta_key')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();
    }
}
