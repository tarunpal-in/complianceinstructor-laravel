<?php

declare(strict_types=1);

namespace App\Seo;

use App\Contracts\Seoable;
use Illuminate\Database\Eloquent\Model;

/**
 * A public, SEO-editable record with its effective title and description
 * resolved once, for the audit to check.
 */
final class SeoRecord
{
    public function __construct(
        public readonly Model&Seoable $model,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $url,
    ) {}
}
