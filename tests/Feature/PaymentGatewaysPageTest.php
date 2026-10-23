<?php

declare(strict_types=1);

use App\Enums\PaymentGateway;
use App\Enums\UserRole;
use App\Filament\Pages\PaymentGateways as PaymentGatewaysPage;
use App\Models\Setting;
use App\Support\PaymentGateways as Gateways;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

/*
 * The Payment Gateways admin page — §8 improvement #1. One switch per
 * customer-facing gateway, each shown with its connection status. The flag is a
 * `payments` setting gated by the commerce (money) permission; Stripe ships off
 * at the client's request, PayPal on.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('defaults to PayPal on and Stripe off, with nothing seeded', function (): void {
    // PayPal on → both PayPal-backed options (card + wallet); Stripe off → no
    // Stripe option.
    expect(Gateways::isEnabled(PaymentGateway::PayPal))->toBeTrue()
        ->and(Gateways::isEnabled(PaymentGateway::Stripe))->toBeFalse()
        ->and(Gateways::enabledValues())->toBe(['card', 'paypal'])
        ->and(Gateways::optionGateway('card'))->toBe(PaymentGateway::PayPal)
        ->and(Gateways::optionFunding('card'))->toBe('card')
        ->and(Gateways::optionGateway('paypal'))->toBe(PaymentGateway::PayPal);
});

it('is reachable by finance but not by a content editor', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));
    expect(PaymentGatewaysPage::canAccess())->toBeFalse();

    $this->actingAs(userWithRole(UserRole::Finance));
    expect(PaymentGatewaysPage::canAccess())->toBeTrue();
});

it('shows both gateways with a connection status', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire::test(PaymentGatewaysPage::class)
        ->assertSuccessful()
        ->assertSee('PayPal')
        ->assertSee('Stripe')
        ->assertSee('Offer Stripe (card) at checkout')
        // No credentials in tests, so neither is connected.
        ->assertSee('not connected');
});

it('turns a gateway off and on, writing the setting', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire::test(PaymentGatewaysPage::class)
        ->set('data.paypal_enabled', false)
        ->set('data.stripe_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Gateways::isEnabled(PaymentGateway::PayPal))->toBeFalse()
        ->and(Gateways::isEnabled(PaymentGateway::Stripe))->toBeTrue()
        ->and((bool) Setting::declared('payments.stripe_enabled'))->toBeTrue();
});
