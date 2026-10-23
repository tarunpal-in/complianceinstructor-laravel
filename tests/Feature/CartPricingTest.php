<?php

declare(strict_types=1);

use App\Cart\CartPricing;
use App\Enums\Format;
use App\Models\Offering;
use App\Models\Webinar;

/*
 * The add-on cross-sell pricing rule (§5.3 / §14 #1). Per webinar, the most
 * expensive offering is the primary at full price; every other offering of that
 * webinar — single format or bundle — is charged at its add-on price. Packages
 * are always standalone.
 *
 * fmtOffering() and cartOf() are shared helpers in tests/Pest.php.
 */

it('charges a lone format at its full price', function (): void {
    $webinar = Webinar::factory()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, 6500);

    expect(cartOf([[$live, 1]])->subtotalCents())->toBe(17900);
});

it('charges a secondary format of the same webinar at its add-on price', function (): void {
    $webinar = Webinar::factory()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, null);
    $transcript = fmtOffering($webinar, Format::Transcript, 7900, 6500);

    // $179 primary + $65 add-on = $244 — the client's worked example.
    expect(cartOf([[$live, 1], [$transcript, 1]])->subtotalCents())->toBe(24400);
});

it('keeps the most expensive format as primary regardless of add order', function (): void {
    $webinar = Webinar::factory()->create();
    // Transcript added first, Live second: Live is still the primary.
    $transcript = fmtOffering($webinar, Format::Transcript, 7900, 6500);
    $live = fmtOffering($webinar, Format::Live, 17900, 9000);

    $cart = cartOf([[$transcript, 1], [$live, 1]]);
    $prices = app(CartPricing::class)->unitPrices($cart->items);

    $liveItem = $cart->items->firstWhere('offering_id', $live->getKey());
    $transcriptItem = $cart->items->firstWhere('offering_id', $transcript->getKey());

    expect($prices[$liveItem->getKey()])->toBe(17900)      // full
        ->and($prices[$transcriptItem->getKey()])->toBe(6500); // add-on
});

it('multiplies the add-on price by quantity', function (): void {
    $webinar = Webinar::factory()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, null);
    $transcript = fmtOffering($webinar, Format::Transcript, 7900, 6500);

    // 17900 + (6500 x 2)
    expect(cartOf([[$live, 1], [$transcript, 2]])->subtotalCents())->toBe(30900);
});

it('add-on-prices a multi-format bundle when it is not the primary', function (): void {
    $webinar = Webinar::factory()->create();
    $bundle = Offering::factory()->withFormats(Format::Live, Format::Recorded)->create([
        'offerable_type' => Webinar::class, 'offerable_id' => $webinar->getKey(),
        'price_cents' => 15000, 'addon_price_cents' => 6500, 'is_active' => true,
    ]);
    $live = fmtOffering($webinar, Format::Live, 19900, 6500);

    // Live (19900) is the most expensive → primary at full price; the cheaper
    // bundle is charged its add-on price (6500). 19900 + 6500.
    expect(cartOf([[$bundle, 1], [$live, 1]])->subtotalCents())->toBe(26400);
});

it('keeps the most expensive offering as primary even when it is a bundle', function (): void {
    $webinar = Webinar::factory()->create();
    $bundle = Offering::factory()->withFormats(Format::Live, Format::Recorded, Format::Transcript)->create([
        'offerable_type' => Webinar::class, 'offerable_id' => $webinar->getKey(),
        'price_cents' => 22900, 'addon_price_cents' => 6500, 'is_active' => true,
    ]);
    $recorded = fmtOffering($webinar, Format::Recorded, 15900, 6500);

    // Bundle (22900) is the most expensive → primary at full price; the single
    // recorded is the add-on (6500). 22900 + 6500.
    expect(cartOf([[$bundle, 1], [$recorded, 1]])->subtotalCents())->toBe(29400);
});

it('freezes the add-on prices onto the placed order', function (): void {
    $webinar = Webinar::factory()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, null);
    $transcript = fmtOffering($webinar, Format::Transcript, 7900, 6500);
    $cart = cartOf([[$live, 1], [$transcript, 1]]);

    $order = app(App\Actions\Orders\PlaceOrder::class)(
        $cart,
        new App\Checkout\BillingDetails('Buyer', 'buyer@example.test'),
        null,
        null,
        [],
    );

    expect($order->total_cents)->toBe(24400);

    $transcriptLine = $order->items()->where('offering_id', $transcript->getKey())->sole();
    expect($transcriptLine->unit_price_cents)->toBe(6500)      // the add-on price
        ->and($transcriptLine->line_total_cents)->toBe(6500);
});

it('prices each webinar independently', function (): void {
    $a = Webinar::factory()->create();
    $b = Webinar::factory()->create();
    $liveA = fmtOffering($a, Format::Live, 17900, null);
    $transcriptA = fmtOffering($a, Format::Transcript, 7900, 6500);
    $liveB = fmtOffering($b, Format::Live, 19900, null);

    // A: 17900 + 6500 add-on. B: 19900 full (its own primary).
    expect(cartOf([[$liveA, 1], [$transcriptA, 1], [$liveB, 1]])->subtotalCents())->toBe(44300);
});
