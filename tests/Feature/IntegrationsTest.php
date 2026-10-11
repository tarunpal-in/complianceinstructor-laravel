<?php

declare(strict_types=1);

use App\Models\Setting;

/**
 * GA4 / GTM / Search Console / Tawk.to — client SEO brief §14.
 *
 * Each renders only when the client has pasted a valid ID, so an empty or
 * mistyped value never reaches the page and breaks it.
 */
function homeHtml(): string
{
    return test()->get(route('home'))->assertSuccessful()->getContent();
}

it('renders nothing when no integration is configured', function (): void {
    $html = homeHtml();

    expect($html)->not->toContain('googletagmanager.com')
        ->and($html)->not->toContain('embed.tawk.to')
        ->and($html)->not->toContain('google-site-verification');
});

it('loads Google Tag Manager when a container is set', function (): void {
    Setting::set('integrations.gtm_id', 'GTM-ABC123', 'string');

    $html = homeHtml();

    expect($html)->toContain('GTM-ABC123')
        ->toContain('googletagmanager.com/gtm.js')
        // The noscript fallback at the end of the body.
        ->toContain('googletagmanager.com/ns.html?id=GTM-ABC123');
});

it('loads GA4 directly when set without a tag manager', function (): void {
    Setting::set('integrations.google_analytics_id', 'G-XYZ789', 'string');

    expect(homeHtml())
        ->toContain('gtag/js?id=G-XYZ789')
        ->toContain("gtag('config', 'G-XYZ789')");
});

/*
 * Loading GA4 both directly and through Tag Manager double-counts every page
 * view, so when both are set the Tag Manager wins and the direct tag is dropped.
 */
it('does not load GA4 directly when a tag manager is also set', function (): void {
    Setting::set('integrations.gtm_id', 'GTM-ABC123', 'string');
    Setting::set('integrations.google_analytics_id', 'G-XYZ789', 'string');

    expect(homeHtml())
        ->toContain('GTM-ABC123')
        ->not->toContain('gtag/js?id=G-XYZ789');
});

it('adds the Search Console verification meta tag', function (): void {
    Setting::set('integrations.gsc_verification', 'abc123verificationcode', 'string');

    expect(homeHtml())->toContain('<meta name="google-site-verification" content="abc123verificationcode">');
});

it('loads the Tawk.to widget when set', function (): void {
    Setting::set('integrations.tawkto_id', '65f0abc/1ab2cd', 'string');

    expect(homeHtml())->toContain('embed.tawk.to/65f0abc/1ab2cd');
});

/*
 * A value pasted with its surrounding tag or in the wrong shape is rejected, so
 * it cannot break the markup it would land in.
 */
it('rejects a malformed value rather than rendering it', function (): void {
    Setting::set('integrations.gtm_id', 'not a container', 'string');
    Setting::set('integrations.gsc_verification', '<meta name="x">', 'string');
    Setting::set('integrations.tawkto_id', 'https://embed.tawk.to/evil', 'string');

    $html = homeHtml();

    expect($html)->not->toContain('not a container')
        ->and($html)->not->toContain('<meta name="x">')
        ->and($html)->not->toContain('embed.tawk.to/evil');
});
