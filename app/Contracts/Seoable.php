<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A content record that carries editable SEO — the contract App\Seo\Seo resolves
 * against.
 *
 * The methods are all provided by App\Models\Concerns\HasSeo; this interface
 * exists so the resolver depends on a capability rather than a concrete trait
 * (a trait is not a type), and so a model that wants different fallbacks can
 * satisfy the contract its own way.
 *
 * The `seo()` relation is deliberately not declared here. The resolver reaches
 * it only through the accessor methods below, and pinning its generic
 * declaring-model type in an interface fights PHPStan's invariance rules for no
 * benefit — the trait already declares it precisely on each model.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
interface Seoable
{
    public function seoTitle(): ?string;

    public function seoDescription(): ?string;

    public function seoFocusKeyword(): ?string;

    public function seoCanonicalOverride(): ?string;

    public function seoRobots(): ?string;

    public function seoOgImageUrl(): ?string;

    public function defaultSeoTitle(): string;

    public function defaultSeoDescription(): ?string;

    public function defaultSeoOgImageUrl(): ?string;
}
