<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Support\Integrations;
use App\Support\Recaptcha;
use Illuminate\Support\Facades\Http;

/*
 * Paid-marketing tracking and reCAPTCHA are driven entirely by the ids/keys the
 * client pastes in Settings → Integrations: a tag fires only once its id is set,
 * and reCAPTCHA guards forms only once both keys are set — never a code change.
 */

it('reads a valid Meta pixel id and rejects a malformed one', function (): void {
    expect(Integrations::facebookPixelId())->toBeNull();

    Setting::set('integrations.facebook_pixel_id', '123456789012345', 'string');
    expect(Integrations::facebookPixelId())->toBe('123456789012345');

    Setting::set('integrations.facebook_pixel_id', '<script>evil', 'string');
    expect(Integrations::facebookPixelId())->toBeNull();
});

it('suppresses the Google Ads tag when Tag Manager is set', function (): void {
    Setting::set('integrations.google_ads_id', 'AW-123456789', 'string');
    expect(Integrations::googleAdsId())->toBe('AW-123456789');

    Setting::set('integrations.gtm_id', 'GTM-ABC123', 'string');
    expect(Integrations::googleAdsId())->toBeNull();
});

it('reads a LinkedIn partner id', function (): void {
    Setting::set('integrations.linkedin_partner_id', '1234567', 'string');
    expect(Integrations::linkedInPartnerId())->toBe('1234567');
});

it('treats reCAPTCHA as configured only when both keys are present', function (): void {
    Setting::set('integrations.recaptcha_site_key', str_repeat('a', 40), 'string');
    expect(Integrations::recaptchaConfigured())->toBeFalse();

    Setting::set('integrations.recaptcha_secret_key', str_repeat('b', 40), 'string');
    expect(Integrations::recaptchaConfigured())->toBeTrue();
});

it('renders the tracking tags on a public page once their ids are set', function (): void {
    Setting::set('integrations.facebook_pixel_id', '123456789012345', 'string');
    Setting::set('integrations.linkedin_partner_id', '1234567', 'string');
    Setting::set('integrations.google_ads_id', 'AW-123456789', 'string');
    Setting::set('integrations.recaptcha_site_key', str_repeat('a', 40), 'string');

    $this->get('/')
        ->assertSuccessful()
        ->assertSee("fbq('init', '123456789012345')", false)
        ->assertSee('_linkedin_partner_id = "1234567"', false)
        ->assertSee('gtag/js?id=AW-123456789', false)
        ->assertSee('recaptcha/api.js?render='.str_repeat('a', 40), false);
});

it('renders no tracking tags when nothing is configured', function (): void {
    $this->get('/')
        ->assertSuccessful()
        ->assertDontSee('connect.facebook.net', false)
        ->assertDontSee('snap.licdn.com', false)
        ->assertDontSee('recaptcha/api.js', false);
});

// Recaptcha::verify ----------------------------------------------------------

it('passes verification through when reCAPTCHA is not configured', function (): void {
    expect(Recaptcha::verify(null, 'newsletter'))->toBeTrue();
});

it('verifies a good token, and rejects low score or wrong action', function (): void {
    Setting::set('integrations.recaptcha_site_key', str_repeat('a', 40), 'string');
    Setting::set('integrations.recaptcha_secret_key', str_repeat('b', 40), 'string');

    Http::fakeSequence()
        ->push(['success' => true, 'score' => 0.9, 'action' => 'newsletter'])
        ->push(['success' => true, 'score' => 0.1, 'action' => 'newsletter'])
        ->push(['success' => true, 'score' => 0.9, 'action' => 'login']);

    expect(Recaptcha::verify('tok', 'newsletter'))->toBeTrue();
    expect(Recaptcha::verify('tok', 'newsletter'))->toBeFalse();   // low score
    expect(Recaptcha::verify('tok', 'newsletter'))->toBeFalse();   // wrong action
});

it('rejects a missing token when configured, and fails open on a Google error', function (): void {
    Setting::set('integrations.recaptcha_site_key', str_repeat('a', 40), 'string');
    Setting::set('integrations.recaptcha_secret_key', str_repeat('b', 40), 'string');

    expect(Recaptcha::verify('', 'newsletter'))->toBeFalse();

    Http::fake(['*' => Http::response('', 500)]);
    expect(Recaptcha::verify('tok', 'newsletter'))->toBeTrue();
});
