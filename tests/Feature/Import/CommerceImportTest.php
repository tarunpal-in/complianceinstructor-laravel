<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Import\Importers\CouponImporter;
use App\Import\Importers\CouponRedemptionImporter;
use App\Import\Importers\OrderImporter;
use App\Import\Importers\PaymentImporter;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\ImportMap;
use App\Models\Offering;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 3 — commerce history. One completed order with a line item, its format
 * snapshot, a named attendee, a payment, a refund, a coupon and its redemption,
 * built from a legacy fixture mirroring the real WooCommerce shapes. This is the
 * money/access-critical stretch, so it is asserted end to end.
 */

beforeEach(function (): void {
    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wp_', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('legacy');

    $s = Schema::connection('legacy');
    $s->create('posts', function ($t): void {
        $t->integer('ID')->primary();
        $t->text('post_title')->nullable();
        $t->string('post_status')->default('publish');
        $t->string('post_type')->default('post');
        $t->integer('post_parent')->default(0);
        $t->text('post_excerpt')->nullable();
        $t->dateTime('post_date')->nullable();
    });
    $s->create('postmeta', function ($t): void {
        $t->increments('meta_id');
        $t->integer('post_id');
        $t->string('meta_key');
        $t->text('meta_value')->nullable();
    });
    $s->create('woocommerce_order_items', function ($t): void {
        $t->integer('order_item_id')->primary();
        $t->text('order_item_name')->nullable();
        $t->string('order_item_type');
        $t->integer('order_id');
    });
    $s->create('woocommerce_order_itemmeta', function ($t): void {
        $t->increments('meta_id');
        $t->integer('order_item_id');
        $t->string('meta_key');
        $t->text('meta_value')->nullable();
    });
    $s->create('wc_order_coupon_lookup', function ($t): void {
        $t->integer('order_id');
        $t->integer('coupon_id');
        $t->dateTime('date_created')->nullable();
        $t->decimal('discount_amount', 12, 2)->default(0);
    });
});

function lg(): Illuminate\Database\Connection
{
    return DB::connection('legacy');
}

function pm(int $postId, array $kv): void
{
    foreach ($kv as $k => $v) {
        lg()->table('postmeta')->insert(['post_id' => $postId, 'meta_key' => $k, 'meta_value' => $v]);
    }
}

/** Seed the app-side catalogue + user and map the legacy ids onto them. */
function seedCommercePrereqs(): array
{
    $user = User::factory()->create(['email' => 'buyer@example.com']);
    $webinar = Webinar::factory()->create();
    $offering = Offering::factory()->create([
        'offerable_type' => $webinar->getMorphClass(),
        'offerable_id' => $webinar->getKey(),
    ]);

    ImportMap::remember('user', 1245, $user);
    ImportMap::remember('webinar', 18859, $webinar);
    ImportMap::remember('offering', 18860, $offering);

    return [$user, $webinar, $offering];
}

function seedCommerceFixture(): void
{
    // Order (completed) + meta.
    lg()->table('posts')->insert([
        'ID' => 5000, 'post_type' => 'shop_order', 'post_status' => 'wc-completed',
        'post_date' => '2023-01-19 10:00:00',
    ]);
    pm(5000, [
        '_customer_user' => '1245', '_order_total' => '179.00', '_cart_discount' => '10.00',
        '_order_tax' => '0', '_order_currency' => 'USD', '_alg_wc_full_custom_order_number' => '23-01-19-001',
        '_transaction_id' => 'TXN-ABC', '_payment_method' => 'stripe', '_date_paid' => '1674120000',
        '_billing_first_name' => 'William', '_billing_last_name' => 'Zmija', '_billing_email' => 'w@z.com',
        '_billing_job_title' => 'VP Operations', '_billing_company' => 'Markdom',
        'billing1_first_name' => 'Attendee', 'billing1_last_name' => 'One', 'billing1_email' => 'att1@z.com',
    ]);

    // Line item + itemmeta.
    lg()->table('woocommerce_order_items')->insert([
        'order_item_id' => 9001, 'order_item_name' => 'Payroll Webinar - LIVE',
        'order_item_type' => 'line_item', 'order_id' => 5000,
    ]);
    foreach ([
        '_product_id' => '18859', '_variation_id' => '18860', '_qty' => '1',
        '_line_total' => '179', '_line_subtotal' => '179', 'pa_addons' => 'live',
    ] as $k => $v) {
        lg()->table('woocommerce_order_itemmeta')->insert(['order_item_id' => 9001, 'meta_key' => $k, 'meta_value' => $v]);
    }

    // Coupon + its redemption.
    lg()->table('posts')->insert([
        'ID' => 6000, 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'post_title' => 'save10',
    ]);
    pm(6000, ['discount_type' => 'fixed_cart', 'coupon_amount' => '10', 'usage_limit' => '0', 'usage_limit_per_user' => '0']);
    lg()->table('wc_order_coupon_lookup')->insert([
        'order_id' => 5000, 'coupon_id' => 6000, 'date_created' => '2023-01-19 10:01:00', 'discount_amount' => 10,
    ]);

    // Refund against the order.
    lg()->table('posts')->insert([
        'ID' => 5001, 'post_type' => 'shop_order_refund', 'post_parent' => 5000,
        'post_date' => '2023-02-01 09:00:00', 'post_excerpt' => 'Customer request',
    ]);
    pm(5001, ['_refund_amount' => '179']);
}

function runCommerce(): void
{
    (new CouponImporter)->run();
    (new OrderImporter)->run();
    (new PaymentImporter)->run();
    (new CouponRedemptionImporter)->run();
}

// -----------------------------------------------------------------------------

it('imports an order with its line item, formats and attendee', function (): void {
    [$user, $webinar, $offering] = seedCommercePrereqs();
    seedCommerceFixture();
    runCommerce();

    $order = Order::query()->sole();
    expect($order->number)->toBe('23-01-19-001')
        ->and($order->user_id)->toBe($user->getKey())
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->total_cents)->toBe(17900)
        ->and($order->discount_cents)->toBe(1000)
        ->and($order->currency)->toBe('USD')
        ->and($order->billing_snapshot['name'])->toBe('William Zmija')
        ->and($order->billing_snapshot['job_title'])->toBe('VP Operations');

    $item = $order->items()->sole();
    expect($item->webinar_id)->toBe($webinar->getKey())
        ->and($item->offering_id)->toBe($offering->getKey())
        ->and($item->line_total_cents)->toBe(17900)
        ->and($item->formats->pluck('format')->all())->toBe([Format::Live]);

    $attendee = $item->attendees()->sole();
    expect($attendee->name)->toBe('Attendee One')->and($attendee->email)->toBe('att1@z.com');
});

it('falls back to the billing email for an attendee with none of their own', function (): void {
    seedCommercePrereqs();
    seedCommerceFixture();
    // Attendee has a name but no email of their own.
    lg()->table('postmeta')->where('post_id', 5000)->where('meta_key', 'billing1_email')->delete();

    runCommerce();

    $attendee = Order::query()->sole()->items()->sole()->attendees()->sole();
    expect($attendee->name)->toBe('Attendee One')
        ->and($attendee->email)->toBe('w@z.com'); // the order's billing email
});

it('splits a roster pasted into one attendee field', function (): void {
    seedCommercePrereqs();
    seedCommerceFixture();
    // A whole team crammed into billing1 (real dirty data).
    lg()->table('postmeta')->where('post_id', 5000)->whereIn('meta_key', ['billing1_first_name', 'billing1_last_name', 'billing1_email'])->delete();
    pm(5000, [
        'billing1_first_name' => 'Osvaldo Fraire - osvaldo@astroturf.com, Karen Burnette - karen@astroturf.com',
        'billing1_email' => 'osvaldo@astroturf.com, karen@astroturf.com',
    ]);

    runCommerce();

    $emails = Order::query()->sole()->items()->sole()->attendees()->pluck('email')->sort()->values()->all();
    expect($emails)->toBe(['karen@astroturf.com', 'osvaldo@astroturf.com']);
});

it('records the payment with its gateway transaction id', function (): void {
    seedCommercePrereqs();
    seedCommerceFixture();
    runCommerce();

    $payment = Payment::query()->sole();
    expect($payment->gateway)->toBe(PaymentGateway::Stripe)
        ->and($payment->gateway_payment_id)->toBe('TXN-ABC')
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->amount_cents)->toBe(17900)
        ->and($payment->processed_at)->not->toBeNull();
});

it('records the refund against the payment', function (): void {
    seedCommercePrereqs();
    seedCommerceFixture();
    runCommerce();

    $refund = Refund::query()->sole();
    expect($refund->payment_id)->toBe(Payment::query()->sole()->getKey())
        ->and($refund->amount_cents)->toBe(17900)
        ->and($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->reason)->toBe('Customer request');
});

it('imports the coupon and its redemption', function (): void {
    [$user] = seedCommercePrereqs();
    seedCommerceFixture();
    runCommerce();

    $coupon = Coupon::query()->sole();
    expect($coupon->code)->toBe('SAVE10') // uppercased on save
        ->and($coupon->amount_off_cents)->toBe(1000)
        ->and($coupon->percent_off)->toBeNull();

    $redemption = CouponRedemption::query()->sole();
    expect($redemption->coupon_id)->toBe($coupon->getKey())
        ->and($redemption->user_id)->toBe($user->getKey())
        ->and($redemption->amount_cents)->toBe(1000);
});

it('maps a duplicate coupon code onto the existing coupon', function (): void {
    // Two legacy coupons share a code (as happens in WordPress, or against a seed).
    lg()->table('posts')->insert([
        ['ID' => 6000, 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'post_title' => 'TRAINING'],
        ['ID' => 6001, 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'post_title' => 'training'],
    ]);
    pm(6000, ['discount_type' => 'fixed_cart', 'coupon_amount' => '10']);
    pm(6001, ['discount_type' => 'fixed_cart', 'coupon_amount' => '10']);

    (new CouponImporter)->run();

    expect(Coupon::query()->where('code', 'TRAINING')->count())->toBe(1)
        ->and(ImportMap::localId('coupon', 6000))->toBe(ImportMap::localId('coupon', 6001));
});

it('keeps a guest order (no customer) with its billing snapshot', function (): void {
    seedCommercePrereqs();
    seedCommerceFixture();
    // Make it a guest checkout.
    lg()->table('postmeta')->where('post_id', 5000)->where('meta_key', '_customer_user')->update(['meta_value' => '0']);

    runCommerce();

    $order = Order::query()->sole();
    expect($order->user_id)->toBeNull()
        ->and($order->billing_snapshot['email'])->toBe('w@z.com');
});

it('is idempotent across runs', function (): void {
    seedCommercePrereqs();
    seedCommerceFixture();
    runCommerce();
    runCommerce();

    expect(Order::query()->count())->toBe(1)
        ->and(Order::query()->sole()->items()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Refund::query()->count())->toBe(1)
        ->and(Coupon::query()->count())->toBe(1)
        ->and(CouponRedemption::query()->count())->toBe(1);
});
