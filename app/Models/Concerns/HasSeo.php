<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\SeoMeta;
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
