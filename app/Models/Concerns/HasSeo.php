<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Redirect;
use App\Models\SeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Provides everything App\Contracts\Seoable requires. A model using this trait
 * satisfies that interface, which is what App\Seo\Seo type-hints — so a model
 * must still declare `implements Seoable`, and the trait fills it in.
 *
 * Gives a content model its editable SEO overrides.
 *
 * Added to every type the brief names — webinars, articles, speakers, pages and
 * the two kinds of category. The row is optional: a model with none falls back
 * to values computed from its own content (App\Seo\Seo), so adding this trait
 * costs nothing until somebody fills a field in.
 *
 * What this trait deliberately does **not** do is decide the fallbacks. A model
 * knows its own title and summary better than a generic resolver could, so each
 * model that wants a non-obvious default implements `defaultSeoTitle()` /
 * `defaultSeoDescription()`. The trait only wires up storage and the
 * convenience of reading a single field with its override applied.
 */
trait HasSeo
{
    /**
     * Keep a page reachable at its old URL after its slug changes — brief §2/§4.
     *
     * "Permanent URLs" cannot mean "never changed", because a title gets fixed
     * and a slug follows it. It means the old URL keeps working: a 301 is written
     * from the old path to the new one automatically, so links and search equity
     * survive the rename without anybody remembering to add a redirect.
     *
     * Only for types with a public URL — `seoPathForSlug` returns null for the
     * rest (a speaker has no page yet), and then there is nothing to redirect.
     */
    public static function bootHasSeo(): void
    {
        static::updated(function (Model $model): void {
            if (! $model->wasChanged('slug')) {
                return;
            }

            $old = $model->getOriginal('slug');
            $new = $model->getAttribute('slug');

            if (! is_string($old) || $old === '' || $old === $new) {
                return;
            }

            /** @var self $model */
            $oldPath = $model->seoPathForSlug($old);
            $newPath = $model->seoPathForSlug((string) $new);

            if ($oldPath === null || $newPath === null) {
                return;
            }

            Redirect::recordMove($oldPath, $newPath, Redirect::SOURCE_SLUG_CHANGE);
        });

        // Keep the cached sitemap current. Only types with a public URL are in
        // it, so only their changes need to bust it.
        $bust = function (Model $model): void {
            // A Seoable without a slug column (a fixed core page) has no
            // slug-derived path; reading `slug` would throw under strict mode.
            if (! array_key_exists('slug', $model->getAttributes())) {
                return;
            }

            /** @var self $model */
            if ($model->seoPathForSlug((string) $model->getAttribute('slug')) !== null) {
                \Illuminate\Support\Facades\Cache::forget(\App\Seo\Sitemap::CACHE_KEY);
            }
        };

        static::saved($bust);
        static::deleted($bust);
    }

    /**
     * Whether this record may appear in search and in the sitemap.
     *
     * A page the SEO team set to noindex is kept out of both. The default (no
     * robots override) is indexable.
     */
    public function isIndexable(): bool
    {
        return ! str_contains(strtolower((string) $this->seoRobots()), 'noindex');
    }

    /**
     * The public path this record would have with a given slug, or null.
     *
     * Null by default — not every SEO-editable type has a public URL yet. The
     * three that do (webinar, article, page) override this, and it is what the
     * slug-change redirect is built from.
     */
    public function seoPathForSlug(string $slug): ?string
    {
        return null;
    }

    /** @return MorphOne<SeoMeta, $this> */
    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /**
     * The edited SEO title, or null to let the resolver compute one.
     *
     * Reads through the loaded relation where possible, so rendering a page does
     * not fire a query per field.
     */
    public function seoTitle(): ?string
    {
        return self::cleaned($this->seo?->title);
    }

    public function seoDescription(): ?string
    {
        return self::cleaned($this->seo?->description);
    }

    public function seoFocusKeyword(): ?string
    {
        return self::cleaned($this->seo?->keywords);
    }

    public function seoCanonicalOverride(): ?string
    {
        return self::cleaned($this->seo?->canonical);
    }

    public function seoRobots(): ?string
    {
        return self::cleaned($this->seo?->robots);
    }

    public function seoOgImageUrl(): ?string
    {
        return $this->seo?->ogImageUrl();
    }

    /**
     * The model's own best title, used when no override is set.
     *
     * A sensible default for anything with a `title` or `name`; a model orders
     * it differently — a webinar wants its accreditation in the tag — by
     * overriding this.
     */
    public function defaultSeoTitle(): string
    {
        $attributes = $this->getAttributes();

        return (string) ($attributes['title'] ?? $attributes['name'] ?? '');
    }

    /** The model's own best description. Overridden where a summary column exists. */
    public function defaultSeoDescription(): ?string
    {
        $attributes = $this->getAttributes();

        return self::cleaned((string) ($attributes['summary'] ?? $attributes['excerpt'] ?? $attributes['description'] ?? ''));
    }

    /**
     * The model's own best social image, used when no override is set.
     *
     * Null by default; a model with a main image returns it. The brief's rule —
     * a webinar's banner, not the company logo — is the webinar overriding this,
     * which keeps "use the relevant image" a property of the content rather than
     * a special case in the renderer.
     */
    public function defaultSeoOgImageUrl(): ?string
    {
        return null;
    }

    /** Trim to null, so a blank override is treated as "not set", not as "empty title". */
    private static function cleaned(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
