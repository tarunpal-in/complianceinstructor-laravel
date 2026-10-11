<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * One thing the SEO audit found worth a look.
 *
 * A typed object rather than an array shape, for the reason the report rows are:
 * PHPStan's Collection template is not covariant and array shapes do not compose
 * through it.
 */
final class SeoFinding
{
    public function __construct(
        public readonly string $subject,
        public readonly string $detail,
        public readonly ?string $url,
    ) {}
}
