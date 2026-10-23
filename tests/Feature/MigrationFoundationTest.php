<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Import\Hashing\LegacyAwareHasher;
use App\Import\Hashing\Phpass;
use App\Import\Hashing\WordPressHash;
use App\Import\Legacy;
use App\Import\Maps\AddonFormats;
use App\Import\Maps\LegacyGateway;
use App\Import\Maps\LegacyOrderStatus;
use App\Import\Support\LegacyDuration;
use App\Models\ImportMap;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Support\Facades\Hash;

/*
 * Phase 0 of the WordPress migration — the foundation: the pure mapping layer,
 * the idempotency backbone, and the silent legacy-password upgrade. No data
 * moves here; this is the scaffolding every later phase builds on.
 */

// Combo → atomic format expansion --------------------------------------------

it('expands each pa_addons combo into its atomic formats', function (string $slug, array $formats): void {
    expect(AddonFormats::forSlug($slug))->toBe($formats);
})->with([
    ['live', [Format::Live]],
    ['recorded', [Format::Recorded]],
    ['e-transcript', [Format::Transcript]],
    ['download-course', [Format::Download]],
    ['live-recorded', [Format::Live, Format::Recorded]],
    ['recordede-transcript', [Format::Recorded, Format::Transcript]],
    ['livee-transcript', [Format::Live, Format::Transcript]],
    ['liverecordede-transcript', [Format::Live, Format::Recorded, Format::Transcript]],
    ['dvd-transcript', [Format::Recorded, Format::Transcript]],
]);

it('is case/space tolerant and knows live combos', function (): void {
    expect(AddonFormats::forSlug('  LIVE  '))->toBe([Format::Live])
        ->and(AddonFormats::includesLive('liverecordede-transcript'))->toBeTrue()
        ->and(AddonFormats::includesLive('recordede-transcript'))->toBeFalse()
        ->and(AddonFormats::knows('nonsense'))->toBeFalse();
});

it('refuses to silently swallow an unmapped combo', function (): void {
    AddonFormats::forSlug('live-dvd-hologram');
})->throws(RuntimeException::class);

// Order status & gateway ------------------------------------------------------

it('maps WooCommerce statuses, prefixed or not', function (string $wc, OrderStatus $enum): void {
    expect(LegacyOrderStatus::toEnum($wc))->toBe($enum);
})->with([
    ['wc-completed', OrderStatus::Paid],
    ['processing', OrderStatus::Paid],
    ['wc-on-hold', OrderStatus::Pending],
    ['wc-cancelled', OrderStatus::Cancelled],
    ['wc-failed', OrderStatus::Failed],
    ['wc-refunded', OrderStatus::Refunded],
]);

it('maps payment methods to the two drivers plus manual', function (string $method, PaymentGateway $enum): void {
    expect(LegacyGateway::toEnum($method))->toBe($enum);
})->with([
    ['ppec_paypal', PaymentGateway::PayPal],
    ['ppcp-gateway', PaymentGateway::PayPal],
    ['ppcp-card-button-gateway', PaymentGateway::PayPal],
    ['stripe', PaymentGateway::Stripe],
    ['razorpay', PaymentGateway::Manual],
    ['', PaymentGateway::Manual],
]);

it('reads minutes out of the free-text duration field', function (): void {
    expect(LegacyDuration::minutes('90 Minutes'))->toBe(90)
        ->and(LegacyDuration::minutes('60 minutes'))->toBe(60)
        ->and(LegacyDuration::minutes('75 Minutes '))->toBe(75)
        ->and(LegacyDuration::minutes('publish'))->toBeNull()
        ->and(LegacyDuration::minutes(''))->toBeNull()
        ->and(LegacyDuration::minutes(null))->toBeNull();
});

// Idempotency backbone --------------------------------------------------------

it('remembers a legacy id → model mapping idempotently', function (): void {
    $speaker = Speaker::factory()->create();

    ImportMap::remember('speaker', 443, $speaker, 'hash-v1');
    ImportMap::remember('speaker', 443, $speaker, 'hash-v2'); // re-run

    expect(ImportMap::query()->where('legacy_type', 'speaker')->count())->toBe(1)
        ->and(ImportMap::localId('speaker', 443))->toBe($speaker->getKey())
        ->and(ImportMap::localId('speaker', 999))->toBeNull()
        ->and(ImportMap::isUnchanged('speaker', 443, 'hash-v2'))->toBeTrue()
        ->and(ImportMap::isUnchanged('speaker', 443, 'hash-v1'))->toBeFalse();
});

it('resolves foreign keys across model types through the map', function (): void {
    $webinar = Webinar::factory()->create();
    ImportMap::remember('webinar', 10820, $webinar);

    expect(ImportMap::localId('webinar', 10820))->toBe($webinar->getKey());
});

// Silent password migration ---------------------------------------------------

it('verifies a phpass ($P$) hash and flags it for rehash', function (): void {
    $hasher = new LegacyAwareHasher;
    $hash = Phpass::hash('correct horse battery staple');

    expect(str_starts_with($hash, '$P$'))->toBeTrue()
        ->and($hasher->check('correct horse battery staple', $hash))->toBeTrue()
        ->and($hasher->check('wrong password', $hash))->toBeFalse()
        ->and($hasher->needsRehash($hash))->toBeTrue();
});

it('verifies a WordPress 6.8 ($wp$) hash and flags it for rehash', function (): void {
    $hasher = new LegacyAwareHasher;
    $hash = WordPressHash::hashWp('s3cret-pass');

    expect(str_starts_with($hash, '$wp$'))->toBeTrue()
        ->and($hasher->check('s3cret-pass', $hash))->toBeTrue()
        ->and($hasher->check('nope', $hash))->toBeFalse()
        ->and($hasher->needsRehash($hash))->toBeTrue();
});

it('behaves exactly like bcrypt for native hashes', function (): void {
    $hasher = new LegacyAwareHasher;
    $hash = $hasher->make('fresh-password');

    expect(str_starts_with($hash, '$2y$'))->toBeTrue()
        ->and($hasher->check('fresh-password', $hash))->toBeTrue()
        ->and($hasher->needsRehash($hash))->toBeFalse();
});

it('wires the legacy hasher as the default Hash driver', function (): void {
    // The Hash facade routes to the legacy driver, so an imported phpass hash
    // verifies through the app's normal auth path and is marked for rehash.
    $phpass = Phpass::hash('facade-check');

    expect(Hash::check('facade-check', $phpass))->toBeTrue()
        ->and(Hash::needsRehash($phpass))->toBeTrue()
        ->and(str_starts_with(Hash::make('x'), '$2y$'))->toBeTrue();
});

// Source guard ----------------------------------------------------------------

it('reports the legacy source as unconfigured and errors cleanly', function (): void {
    // Make the assertion independent of whatever .env points LEGACY_DB_* at.
    config(['database.connections.legacy.database' => null]);

    expect(Legacy::configured())->toBeFalse();

    $this->artisan('import:status')->assertFailed();
});
