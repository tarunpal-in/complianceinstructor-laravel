<?php

declare(strict_types=1);

use App\Cart\CartManager;
use App\Cart\CrossSell;
use App\Enums\Format;
use App\Livewire\CartDrawer;
use App\Livewire\PurchasePanel;
use App\Models\Offering;
use App\Models\Webinar;
use Livewire\Livewire;

// fmtOffering() and cartOf() are shared helpers in tests/Pest.php.

it('cross-sells the other add-on formats of a webinar in the cart', function (): void {
    $webinar = Webinar::factory()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, null);
    $transcript = fmtOffering($webinar, Format::Transcript, 7900, 6500);

    $cart = cartOf([[$live, 1]]);

    $suggestions = app(CrossSell::class)->forCart($cart);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['offering']->id)->toBe($transcript->id)
        ->and($suggestions[0]['price_cents'])->toBe(6500);
});

it('cross-sells bundles too, labelled by the full offering, at the add-on price', function (): void {
    $webinar = Webinar::factory()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, 6500);
    $bundle = Offering::factory()->withFormats(Format::Recorded, Format::Transcript)->create([
        'offerable_type' => Webinar::class, 'offerable_id' => $webinar->getKey(),
        'price_cents' => 17900, 'addon_price_cents' => 6500, 'is_active' => true,
    ]);

    $cart = cartOf([[$live, 1]]);
    $suggestions = app(CrossSell::class)->forCart($cart);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['offering']->id)->toBe($bundle->id)
        ->and($suggestions[0]['format_label'])->toBe('Recorded + Transcript (PDF)')
        ->and($suggestions[0]['price_cents'])->toBe(6500);
});

it('adds to the cart and opens the drawer from the purchase panel', function (): void {
    $webinar = Webinar::factory()->scheduled()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, null);

    Livewire::test(PurchasePanel::class, ['webinar' => $webinar])
        ->set('quantities.'.$live->id, 1)
        ->call('add')
        ->assertDispatched('cart:open');

    expect(app(CartManager::class)->peek()?->items)->toHaveCount(1);
});

it('adds an add-on from the drawer at its add-on price', function (): void {
    $webinar = Webinar::factory()->create();
    $live = fmtOffering($webinar, Format::Live, 17900, null);
    $transcript = fmtOffering($webinar, Format::Transcript, 7900, 6500);

    app(CartManager::class)->add($live, 1);

    Livewire::test(CartDrawer::class)
        ->call('openDrawer')
        ->assertSet('open', true)
        ->call('addAddon', $transcript->id)
        ->assertSet('open', true);

    $cart = app(CartManager::class)->peek();

    // 17900 (primary, full price) + 6500 (secondary, add-on price) = 24400.
    expect($cart?->items)->toHaveCount(2)
        ->and($cart?->subtotalCents())->toBe(24400);
});

it('renders the webinar page with the purchase panel and drawer', function (): void {
    $webinar = Webinar::factory()->scheduled()->create();
    fmtOffering($webinar, Format::Live, 17900, null);

    $this->get(route('webinars.show', $webinar))
        ->assertSuccessful();
});
