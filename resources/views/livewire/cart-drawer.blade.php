<div>
    @if ($open && $cart && ! $cart->isEmpty())
        <div class="cd-backdrop" wire:click.self="close" @keydown.escape.window="$wire.close()">
            <div class="cd-modal" role="dialog" aria-modal="true" aria-label="Your cart" data-lenis-prevent>
                <div class="cd-head">
                    <h2>Your Cart</h2>
                    <button type="button" class="cd-close" wire:click="close" aria-label="Close">
                        <x-ui.icon name="x" :size="20" />
                    </button>
                </div>

                <div class="cd-body">
                    {{-- Cross-sell: other formats of the webinars in the cart. --}}
                    <div class="cd-cross">
                        @if (! empty($suggestions))
                            <h3>You might also like</h3>
                            @foreach ($suggestions as $s)
                                @php $w = $s['webinar']; $speaker = $w->speaker; @endphp
                                <div class="cd-cross-card" wire:key="xsell-{{ $s['offering']->id }}">
                                    <div class="cd-cross-top">
                                        <div class="cd-cross-avatar">
                                            @if ($speaker && ($p = $speaker->getFirstMediaUrl('portrait', 'thumb')))
                                                <img src="{{ $p }}" alt="">
                                            @endif
                                        </div>
                                        <div>
                                            <strong>{{ $w->title }}</strong>
                                            @if ($speaker)
                                                <span class="cd-cross-speaker">Speaker: {{ $speaker->name }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <button type="button" class="cd-addon-btn" wire:click="addAddon({{ $s['offering']->id }})" wire:loading.attr="disabled">
                                        Add {{ $s['format_label'] }} for {{ \App\Support\Money::format($s['price_cents'], $cart->currency) }}
                                    </button>
                                </div>
                            @endforeach
                        @else
                            <p class="cd-cross-empty">Everything for this webinar is in your cart.</p>
                        @endif
                    </div>

                    {{-- Cart summary. --}}
                    <div class="cd-summary">
                        <h3>Cart Items ({{ $cart->itemCount() }})</h3>

                        <ul class="cd-items">
                            @foreach ($cart->items as $item)
                                @php
                                    $offering = $item->offering;
                                    $w = $offering?->offerable;
                                    $unit = $prices[$item->id] ?? ($offering?->priceCents() ?? 0);
                                @endphp
                                <li wire:key="cart-item-{{ $item->id }}">
                                    <div class="cd-item-main">
                                        <strong>{{ $w?->title ?? $offering?->label() }}</strong>
                                        @if ($w?->speaker)
                                            <span class="cd-item-speaker">Speaker: {{ $w->speaker->name }}</span>
                                        @endif
                                        <span class="cd-item-format">{{ $offering?->label() }}@if ($item->quantity > 1) × {{ $item->quantity }}@endif</span>
                                    </div>
                                    <div class="cd-item-side">
                                        <span class="cd-item-price">{{ \App\Support\Money::format($unit * $item->quantity, $cart->currency) }}</span>
                                        <button type="button" class="cd-item-remove" wire:click="removeItem({{ $item->id }})" aria-label="Remove">
                                            <x-ui.icon name="x" :size="14" />
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        <div class="cd-subtotal">
                            <span>Sub-total</span>
                            <strong>{{ \App\Support\Money::format($cart->subtotalCents(), $cart->currency) }}</strong>
                        </div>

                        <div class="cd-actions">
                            <button type="button" class="cd-continue" wire:click="close">Add More Webinars</button>
                            <a class="cd-checkout" href="{{ route('checkout.index') }}">Proceed to Checkout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
