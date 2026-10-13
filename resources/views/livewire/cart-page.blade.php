@php use App\Support\Money; @endphp

<div class="cart">
    @if (session('cart_status'))
        <p class="cart-flash ok">{{ session('cart_status') }}</p>
    @endif
    @if (session('cart_error'))
        <p class="cart-flash err">{{ session('cart_error') }}</p>
    @endif

    @if ($cart->isEmpty())
        <div class="cart-empty">
            <p>Your cart is empty.</p>
            <a class="btn-primary" href="{{ route('home') }}#training">Browse webinars</a>
        </div>
    @else
        <div class="cart-grid">
            <div class="cart-lines">
                @foreach ($cart->items as $item)
                    <div class="cart-row" wire:key="item-{{ $item->id }}">
                        <div class="cart-row-main">
                            <strong>{{ $item->offering?->offerable?->title ?? 'Item' }}</strong>
                            <span>{{ $item->offering?->label() }}</span>
                            @unless ($item->isAvailable())
                                <em class="cart-unavailable">No longer available</em>
                            @endunless
                        </div>

                        <div class="cart-row-price">{{ Money::format($item->offering?->priceCents() ?? 0, $cart->currency) }}</div>

                        <div class="cart-row-qty" role="group" aria-label="Quantity">
                            <button type="button" wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity - 1 }})" aria-label="Decrease quantity">−</button>
                            <span aria-live="polite">{{ $item->quantity }}</span>
                            <button type="button" wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity + 1 }})" aria-label="Increase quantity">+</button>
                        </div>

                        <div class="cart-row-total">{{ Money::format($item->lineSubtotalCents(), $cart->currency) }}</div>

                        <button type="button" class="cart-remove" wire:click="removeItem({{ $item->id }})" aria-label="Remove {{ $item->offering?->label() }}">
                            <x-ui.icon name="x" :size="18" />
                        </button>
                    </div>
                @endforeach
            </div>

            <aside class="cart-summary">
                <h2>Summary</h2>
                <div class="cart-summary-row">
                    <span>Subtotal</span>
                    <strong>{{ Money::format($cart->subtotalCents(), $cart->currency) }}</strong>
                </div>
                <p class="cart-summary-note">Coupons and tax are applied at checkout.</p>
                <a class="btn-primary cart-checkout" href="{{ route('checkout.index') }}">Proceed to checkout</a>
            </aside>
        </div>
    @endif
</div>
