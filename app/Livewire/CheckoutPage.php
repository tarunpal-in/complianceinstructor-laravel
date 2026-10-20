<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Coupons\ValidateCoupon;
use App\Actions\Orders\PlaceOrder;
use App\Cart\CartManager;
use App\Checkout\BillingDetails;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemFormat;
use App\Models\Webinar;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;

/**
 * Checkout — CLAUDE.md §5.4 / §6 (P3).
 *
 * Collects the buyer's details and a named seat for each unit, previews a coupon
 * against the live cart, and places the order through PlaceOrder — which is the
 * only place money is computed, always from the offering (§14 #1). The totals
 * shown here are the server's on every re-render, never the browser's.
 *
 * Payment is not taken here: placing produces a Pending order, and its status
 * only turns Paid when a verified payment row says so (§14 #2). Capture is the
 * next slice.
 */
class CheckoutPage extends Component
{
    public string $name = '';

    public string $email = '';

    public string $jobTitle = '';

    public string $company = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $region = '';

    public string $postalCode = '';

    public string $country = Countries::DEFAULT;

    /** @var array<int, array{name: string, email: string}> */
    public array $attendees = [];

    public string $couponCode = '';

    public ?string $appliedCoupon = null;

    public int $couponDiscount = 0;

    public ?string $couponError = null;

    public string $gateway = 'paypal';

    public ?string $placeError = null;

    public function mount(): void
    {
        // Checkout requires an account (no guest checkout). The controller already
        // blocks a signed-out visitor; this is the component's own guard.
        if (($user = auth()->user()) === null) {
            $this->redirectRoute('cart.index', navigate: true);

            return;
        }

        $cart = app(CartManager::class)->peek();

        if ($cart === null || $cart->isEmpty()) {
            $this->redirectRoute('cart.index', navigate: true);

            return;
        }

        $this->name = (string) $user->name;
        $this->email = (string) $user->email;

        $this->seedAttendees($cart);
    }

    public function applyCoupon(): void
    {
        $this->couponError = null;
        $code = trim($this->couponCode);

        if ($code === '') {
            $this->couponError = 'Enter a coupon code.';

            return;
        }

        $coupon = Coupon::query()->matchingCode($code)->first();

        if ($coupon === null) {
            $this->resetCoupon();
            $this->couponError = 'That code was not recognised.';

            return;
        }

        // Validated against a preview of the order built from the live cart, so
        // the discount shown is the one the order would actually get (scope,
        // caps and min-spend all honoured). Placing re-validates under a lock.
        $verdict = app(ValidateCoupon::class)($coupon, $this->previewOrder(), auth()->user());

        if (! $verdict->accepted) {
            $this->resetCoupon();
            $this->couponError = $verdict->reason;

            return;
        }

        $this->appliedCoupon = $code;
        $this->couponDiscount = $verdict->discountCents;
    }

    public function removeCoupon(): void
    {
        $this->resetCoupon();
        $this->couponCode = '';
        $this->couponError = null;
    }

    public function place(): mixed
    {
        $this->placeError = null;

        // No guest checkout — if the session lapsed, back to the cart to sign in.
        if (auth()->guest()) {
            return $this->redirectRoute('cart.index', navigate: true);
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'jobTitle' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'addressLine1' => ['required', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['required', 'string', 'max:32'],
            'country' => ['required', 'string', Rule::in(Countries::names())],
            'attendees.*.name' => ['required', 'string', 'max:255'],
            'attendees.*.email' => ['required', 'email', 'max:255'],
            'gateway' => ['required', 'in:paypal,stripe'],
        ], attributes: [
            'jobTitle' => 'job title',
            'addressLine1' => 'address',
            'postalCode' => 'postal code',
        ]);

        $cart = app(CartManager::class)->current();

        if ($cart->isEmpty()) {
            return $this->redirectRoute('cart.index', navigate: true);
        }

        try {
            $order = app(PlaceOrder::class)(
                $cart,
                new BillingDetails(
                    name: $this->name,
                    email: $this->email,
                    company: $this->blankToNull($this->company),
                    country: $this->blankToNull($this->country),
                    jobTitle: $this->blankToNull($this->jobTitle),
                    addressLine1: $this->blankToNull($this->addressLine1),
                    addressLine2: $this->blankToNull($this->addressLine2),
                    city: $this->blankToNull($this->city),
                    region: $this->blankToNull($this->region),
                    postalCode: $this->blankToNull($this->postalCode),
                ),
                auth()->user(),
                $this->appliedCoupon,
                array_values($this->attendees),
            );
        } catch (RuntimeException $exception) {
            // A coupon that went invalid between apply and place, or a race on the
            // last redemption — surface it and drop the coupon rather than place
            // at the wrong price.
            $this->placeError = $exception->getMessage();
            $this->resetCoupon();

            return null;
        }

        // The order is Pending; the cart stays until payment succeeds, so a
        // visitor who abandons payment keeps their cart. On to the gateway.
        session(['recent_order_id' => $order->getKey(), 'checkout_gateway' => $this->gateway]);

        return $this->redirectRoute('checkout.pay', ['order' => $order], navigate: true);
    }

    public function render(): View
    {
        $cart = app(CartManager::class)->current();
        $subtotal = $cart->subtotalCents();
        $discount = $this->appliedCoupon !== null ? min($this->couponDiscount, $subtotal) : 0;
        $tax = 0;
        $total = max(0, $subtotal - $discount + $tax);

        return view('livewire.checkout-page', [
            'cart' => $cart,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
        ]);
    }

    private function resetCoupon(): void
    {
        $this->appliedCoupon = null;
        $this->couponDiscount = 0;
    }

    /** An empty optional field is stored as absent, not an empty string. */
    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function seedAttendees(Cart $cart): void
    {
        $seats = (int) $cart->items
            ->filter(fn ($item): bool => $item->isAvailable())
            ->sum(fn ($item): int => $item->quantity * (int) $item->offering->seats_per_unit);

        $this->attendees = [];

        for ($seat = 0; $seat < $seats; $seat++) {
            // The first seat is the buyer's by default; the rest are captured.
            $this->attendees[$seat] = $seat === 0
                ? ['name' => $this->name, 'email' => $this->email]
                : ['name' => '', 'email' => ''];
        }
    }

    /**
     * An unsaved order mirroring the live cart, so ValidateCoupon can score a
     * coupon without writing anything.
     */
    private function previewOrder(): Order
    {
        $cart = app(CartManager::class)->current();

        $order = new Order(['currency' => $cart->currency, 'user_id' => auth()->id()]);

        $items = $cart->items
            ->filter(fn ($item): bool => $item->isAvailable())
            ->map(function ($item): OrderItem {
                $offering = $item->offering;
                $webinar = $offering->offerable instanceof Webinar ? $offering->offerable : null;
                $price = $offering->priceCents();

                $orderItem = new OrderItem([
                    'offering_id' => $offering->getKey(),
                    'webinar_id' => $webinar?->getKey(),
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $price,
                    'line_total_cents' => $price * $item->quantity,
                ]);

                $orderItem->setRelation(
                    'formats',
                    $offering->formatEnums()->map(fn ($format): OrderItemFormat => new OrderItemFormat(['format' => $format])),
                );

                return $orderItem;
            })
            ->values();

        $order->setRelation('items', $items);
        $order->setRelation('redemptions', collect());

        return $order;
    }
}
