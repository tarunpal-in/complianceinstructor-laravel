<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * The resolved SEO values for one page, ready to render.
 *
 * Everything is already decided by the time a view sees this — overrides
 * applied, fallbacks filled, lengths trimmed, canonical built. The head partial
 * prints these and makes no decisions of its own, which is what keeps "what
 * search engines see" in one testable place rather than scattered across
 * `@section` directives in every template.
 *
 * There is deliberately no `keywords` field. The focus keyword is internal (see
 * the seo_meta migration), and a value object with nowhere to put it is the
 * cleanest guarantee the renderer cannot emit a meta-keywords tag by accident.
 */
final class SeoData
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        /** Absolute, HTTPS, trailing-slash. The one preferred URL for this page. */
        public readonly string $canonical,
        /** e.g. "index, follow" or "noindex, follow". */
        public readonly string $robots,
        public readonly string $ogType,
        /** Absolute URL, or null to omit the tag. */
        public readonly ?string $ogImage,
    ) {}

    /** Whether search engines are allowed to index this page. Drives the sitemap. */
    public function isIndexable(): bool
    {
        return ! str_contains(strtolower($this->robots), 'noindex');
    }
}
