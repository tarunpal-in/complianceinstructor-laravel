<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\ReferencesMedia;
use App\Models\Concerns\RecordsMediaUsage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide configuration (CLAUDE.md §5.6).
 *
 * Read through Setting::get('group.key'), which serves the whole table from one
 * cached array — a public page touching a dozen settings must not become a
 * dozen queries. The cache is dropped whenever a row is written, so a save is
 * visible on the next request without a manual clear.
 */
class Setting extends Model implements ReferencesMedia
{
    use RecordsMediaUsage;

    private const CACHE_KEY = 'settings.all';

    /**
     * Settings whose value is a media id, and what to call that use.
     *
     * The logo and the favicon are the most-referenced files on the site and the
     * ones a client is most likely to tidy away by accident, so they are exactly
     * what the delete guard should be protecting.
     */
    public const MEDIA_KEYS = [
        'brand.logo_media_id' => 'brand.logo',
        'brand.logo_dark_media_id' => 'brand.logo (dark)',
        'brand.favicon_media_id' => 'brand.favicon',
        'brand.og_default_media_id' => 'brand.social image',
    ];

    protected $fillable = ['group', 'key', 'value', 'type'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    protected static function booted(): void
    {
        static::saved(static fn () => static::flush());
        static::deleted(static fn () => static::flush());
    }

    /**
     * @param  string  $key  Dotted "group.key", e.g. "promo_bar.enabled".
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::cached()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value, string $type = 'string'): void
    {
        [$group, $name] = self::split($key);

        static::updateOrCreate(
            ['group' => $group, 'key' => $name],
            ['value' => $value, 'type' => $type],
        );
    }

    /**
     * Every setting, keyed "group.key".
     *
     * @return array<string, mixed>
     */
    public static function cached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, static function (): array {
            // The table may not exist yet on a fresh clone before migrating;
            // callers get defaults rather than a 500 on every page.
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return static::query()
                ->get(['group', 'key', 'value'])
                ->mapWithKeys(static fn (Setting $setting): array => [
                    "{$setting->group}.{$setting->key}" => $setting->value,
                ])
                ->all();
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * A colour that is safe to drop into a style attribute.
     *
     * Settings are admin-authored, but an admin is still an untrusted source for
     * something interpolated into CSS — a stray `;` would let one break out of
     * the declaration. Anything that is not a plain hex colour falls back.
     */
    public static function color(string $key, string $fallback): string
    {
        $value = static::get($key);

        return is_string($value) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1
            ? $value
            : $fallback;
    }

    public static function pixels(string $key, int $fallback, int $min = 8, int $max = 32): int
    {
        $value = static::get($key);

        return is_numeric($value) ? (int) max($min, min($max, (int) $value)) : $fallback;
    }

    /**
     * The media id this row holds, if it is one of the brand media settings.
     *
     * @return array<string, int|null>
     */
    public function mediaUsageMap(): array
    {
        $dotted = "{$this->group}.{$this->key}";
        $context = self::MEDIA_KEYS[$dotted] ?? null;

        if ($context === null || ! is_numeric($this->value)) {
            return [];
        }

        return [$context => (int) $this->value];
    }

    /**
     * A media id held in settings, or null.
     *
     * Validated as numeric before use: the value column is JSON and an admin
     * typo would otherwise reach a query as a string.
     */
    public static function mediaId(string $key): ?int
    {
        $value = static::get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function split(string $key): array
    {
        $parts = explode('.', $key, 2);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }
}
