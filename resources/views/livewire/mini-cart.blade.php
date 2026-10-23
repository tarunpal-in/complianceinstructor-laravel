<div>
    @if ($open)
        <div class="mc-backdrop" wire:click.self="close" @keydown.escape.window="$wire.close()">
            <aside class="mc-panel" role="dialog" aria-modal="true" aria-label="Your cart" data-lenis-prevent>
                <header class="mc-head">
                    <h2>Your Cart</h2>
                    <button type="button" class="mc-close" wire:click="close" aria-label="Close">
                        <x-ui.icon name="x" :size="20" />
                    </button>
                </header>

                @if (! $cart || $cart->isEmpty())
                    <div class="mc-body">
                        <div class="mc-empty">
                            <x-ui.icon name="shopping-cart" :size="40" />
                            <p>Your cart is empty.</p>
                            <a href="{{ route('webinars.index') }}" wire:click="close">Browse webinars</a>
                        </div>
                    </div>
                @else
                    <div class="mc-body">
                        <ul class="mc-items">
                            @foreach ($cart->items as $item)
                                @php
                                    $offering = $item->offering;
                                    $offerable = $offering?->offerable;
                                    // Speaker and the feature image are webinar-only; a package line shows neither.
                                    $webinar = $offerable instanceof \App\Models\Webinar ? $offerable : null;
                                    $image = $webinar?->cardImageUrl();
                                    $unit = $prices[$item->id] ?? ($offering?->priceCents() ?? 0);
                                @endphp
                                <li class="mc-item" wire:key="mini-item-{{ $item->id }}">
                                    <div class="mc-item-thumb">
                                        @if ($image)
                                            <img src="{{ $image }}" alt="" loading="lazy">
                                        @else
                                            <span class="mc-item-thumb-none" aria-hidden="true"></span>
                                        @endif
                                    </div>

                                    <div class="mc-item-main">
                                        <strong>{{ $offerable?->title ?? $offering?->label() }}</strong>
                                        @if ($webinar?->speaker)
                                            <span class="mc-item-speaker">Speaker: {{ $webinar->speaker->name }}</span>
                                        @endif
                                        <span class="mc-item-format">{{ $offering?->label() }}</span>

                                        <div class="mc-qty" role="group" aria-label="Quantity">
                                            <button type="button" wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity - 1 }})" wire:loading.attr="disabled" aria-label="Decrease quantity">&minus;</button>
                                            <span aria-live="polite">{{ $item->quantity }}</span>
                                            <button type="button" wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity + 1 }})" wire:loading.attr="disabled" aria-label="Increase quantity">+</button>
                                        </div>
                                    </div>

                                    <div class="mc-item-side">
                                        <span class="mc-item-price">{{ \App\Support\Money::format($unit * $item->quantity, $cart->currency) }}</span>
                                        <button type="button" class="mc-item-remove" wire:click="removeItem({{ $item->id }})" wire:loading.attr="disabled" aria-label="Remove">
                                            <x-ui.icon name="x" :size="14" />
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <footer class="mc-foot">
                        <div class="mc-subtotal">
                            <span>Sub-total</span>
                            <strong>{{ \App\Support\Money::format($cart->subtotalCents(), $cart->currency) }}</strong>
                        </div>

                        <div class="mc-actions">
                            <a class="mc-view" href="{{ route('cart.index') }}">View cart</a>
                            <a class="mc-checkout" href="{{ route('checkout.index') }}">Checkout</a>
                        </div>
                    </footer>
                @endif
            </aside>
        </div>
    @endif
</div>
