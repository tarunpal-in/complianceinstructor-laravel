<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\ReferencesMedia;
use App\Models\Concerns\RecordsMediaUsage;
use App\Settings\SettingRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

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
        self::assertRegistered($key);

        return self::cached()[$key] ?? $default;
    }

    /**
     * Refuse a key no screen exposes — outside production only.
     *
     * Eleven settings had reached live configuration with nowhere to change
     * them, including the window an agreed refund waits before the money
     * leaves. Each was introduced the same way: somebody added a
     * `Setting::get()` with a sensible default and the admin screen was a
     * separate job that never happened. Two of them were added in the week
     * this guard was written.
     *
     * A static scan cannot catch them all, because keys are built from
     * constants and from enum methods. Failing at the call site does: any code
     * path a test exercises will name the key it forgot to register.
     *
     * Production never throws. A missing registration is a development
     * mistake, and turning it into a 500 for a customer would be the wrong
     * trade — the declared default is a perfectly good answer.
     */
    private static function assertRegistered(string $key): void
    {
        if (app()->isProduction() || SettingRegistry::has($key)) {
            return;
        }

        throw new RuntimeException(
            "Setting [{$key}] is read in code but not declared in SettingRegistry, so nobody can change it. "
            .'Add it to the group it belongs to and give it a field on the settings screen.'
        );
    }

    /**
     * A setting, falling back to the default the registry declares for it.
     *
     * Use this over `get($key, 'some default')` wherever the default is real
     * rather than incidental. A default written at the call site is a default
     * written at every call site: the promo bar's copy existed in the Blade
     * and not in the form, so a fresh install could not save the settings
     * screen at all until somebody typed that field in by hand.
     */
    public static function declared(string $key): mixed
    {
        return SettingRegistry::find($key)?->value();
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
