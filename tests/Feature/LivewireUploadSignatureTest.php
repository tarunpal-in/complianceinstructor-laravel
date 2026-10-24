<?php

declare(strict_types=1);

use App\Seo\TrailingSlash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/*
 * The custom trailing-slash URL generator must leave Livewire's own routes bare.
 * Livewire 4 obfuscates its prefix to livewire-<hash>, so the plain 'livewire'
 * exclusion missed it and the signed upload URL was emitted with a trailing
 * slash — which fails signature validation (a 401) on every environment, which
 * is exactly how the admin "failed to upload" bug showed up.
 */

it('emits the livewire upload url bare, so its signature validates', function (): void {
    $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5));

    expect(str_contains($url, '/upload-file/?'))->toBeFalse()
        ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue();
});

it('excludes Livewire (plain and obfuscated) and keeps content pages slashed', function (): void {
    expect(TrailingSlash::wanted('/livewire-c5c78cbe/upload-file'))->toBeFalse()
        ->and(TrailingSlash::wanted('/livewire/update'))->toBeFalse()
        // Content pages are unaffected — they still take the canonical slash.
        ->and(TrailingSlash::wanted('/about'))->toBeTrue()
        ->and(TrailingSlash::wanted('/webinars'))->toBeTrue();
});
