<?php

declare(strict_types=1);

use App\Enums\EmailCategory;
use App\Enums\Format;
use App\Enums\PaymentStatus;
use App\Filament\Account\Pages\EmailPreferences;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarHandout;
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

it('shows each order\'s line items and the webinar\'s study materials', function (): void {
    $user = User::factory()->create();
    $order = paidOrderFor($user, 'CI-20260-7');
    $webinar = Webinar::factory()->create();
    OrderItem::factory()->for($order)->for($webinar)->create([
        'description' => 'Payroll Compliance 2026 — Recorded',
        'quantity' => 1,
        'line_total_cents' => 20000,
    ]);
    WebinarHandout::factory()->for($webinar)->create(['label' => 'Order Workbook']);
    // The order granted access; handouts are reached through that active grant.
    Entitlement::factory()->fromOrder($order->getKey())->for($webinar)->create([
        'user_id' => $user->getKey(),
        'format' => Format::Recorded,
    ]);

    $this->actingAs($user)
        ->get(route('filament.account.pages.my-orders'))
        ->assertSuccessful()
        ->assertSee('Payroll Compliance 2026 — Recorded')
        ->assertSee('Study materials', escape: false)
        ->assertSee('Order Workbook');
});

it('keeps a past live purchase and its details in order history', function (): void {
    // Once the session passes, the live grant leaves Upcoming — but the order
    // that paid for it must never vanish.
    $user = User::factory()->create();
    $order = paidOrderFor($user, 'CI-20260-8');
    $webinar = Webinar::factory()->create(['scheduled_at' => now()->subMonth()]);
    OrderItem::factory()->for($order)->for($webinar)->create([
        'description' => 'Past Live Session — Live',
    ]);

    $this->actingAs($user)
        ->get(route('filament.account.pages.my-orders'))
        ->assertSuccessful()
        ->assertSee('CI-20260-8')
        ->assertSee('Past Live Session — Live');
});

it('does not offer handouts for an order whose access was revoked', function (): void {
    $user = User::factory()->create();
    $order = paidOrderFor($user, 'CI-20260-9');
    $webinar = Webinar::factory()->create();
    OrderItem::factory()->for($order)->for($webinar)->create(['description' => 'Refunded Session — Recorded']);
    WebinarHandout::factory()->for($webinar)->create(['label' => 'Revoked Workbook']);
    // Refunded: the grant is revoked, so the material must not be offered here.
    Entitlement::factory()->fromOrder($order->getKey())->for($webinar)->revoked()->create([
        'user_id' => $user->getKey(),
        'format' => Format::Recorded,
    ]);

    $this->actingAs($user)
        ->get(route('filament.account.pages.my-orders'))
        ->assertSuccessful()
        ->assertSee('Refunded Session — Recorded') // the order still shows
        ->assertDontSee('Revoked Workbook');        // the handout does not
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
