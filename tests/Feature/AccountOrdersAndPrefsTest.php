<?php

declare(strict_types=1);

use App\Enums\EmailCategory;
use App\Enums\PaymentStatus;
use App\Filament\Account\Pages\EmailPreferences;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Livewire\Livewire;

/*
 * The account's order history, invoices and email preferences — §6 / P5 slice 3.
 */

function paidOrderFor(User $user, string $number): Order
{
    $order = Order::factory()->paid()->create([
        'user_id' => $user->getKey(),
        'number' => $number,
        'total_cents' => 20000,
    ]);
    Payment::factory()->create([
        'order_id' => $order->getKey(),
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => 20000,
    ]);

    return $order;
}

// Orders & invoices ------------------------------------------------------------

it('lists the member their own orders with an invoice link', function (): void {
    $user = User::factory()->create();
    paidOrderFor($user, 'CI-20260-1');

    $this->actingAs($user)
        ->get(route('filament.account.pages.my-orders'))
        ->assertSuccessful()
        ->assertSee('CI-20260-1')
        ->assertSee('/invoice/', escape: false); // the signed invoice URL
});

it('does not show another customer\'s orders', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    paidOrderFor($other, 'CI-OTHER-9');

    $this->actingAs($user)
        ->get(route('filament.account.pages.my-orders'))
        ->assertSuccessful()
        ->assertDontSee('CI-OTHER-9');
});

it('tells a member with no orders there is nothing yet', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('filament.account.pages.my-orders'))
        ->assertSuccessful()
        ->assertSee('placed any orders yet');
});

// Email preferences ------------------------------------------------------------

it('shows the declinable email categories', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('filament.account.pages.email-preferences'))
        ->assertSuccessful()
        ->assertSee(EmailCategory::Marketing->label());
});

it('toggles a category off and back on', function (): void {
    $user = User::factory()->create();

    expect($user->acceptsEmailCategory(EmailCategory::Marketing))->toBeTrue();

    Livewire::actingAs($user)->test(EmailPreferences::class)->call('toggle', 'marketing');
    expect($user->fresh()->acceptsEmailCategory(EmailCategory::Marketing))->toBeFalse();

    Livewire::actingAs($user)->test(EmailPreferences::class)->call('toggle', 'marketing');
    expect($user->fresh()->acceptsEmailCategory(EmailCategory::Marketing))->toBeTrue();
});

it('refuses to toggle a category that cannot be declined', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(EmailPreferences::class)->call('toggle', 'purchase');

    // Nothing was recorded against the undeclinable category, and it still sends.
    expect($user->fresh()->acceptsEmailCategory(EmailCategory::Purchase))->toBeTrue();
});
