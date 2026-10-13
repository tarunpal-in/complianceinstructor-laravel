@props(['webinar'])

@php
    // §5.3 makes `offerings` the only place a price is read from. These rows are
    // the display copy of it; checkout recomputes every total from the same rows
    // inside the order transaction, so nothing here is ever trusted (§14 #1).
    $offerings = $webinar->offerings
        ->where('is_active', true)
        ->sortBy('sort_order')
        ->values();

    $maxQuantity = 10;
@endphp

<div
    class="purchase-panel"
    x-data="purchasePanel({{ Js::from($offerings->mapWithKeys(fn ($offering) => [
        $offering->id => ['price' => $offering->price_cents, 'label' => $offering->label()],
    ])) }})"
>
    <h2 class="purchase-head">
        <x-ui.icon name="shopping-cart" :size="20" />
        Purchase Options
    </h2>

    @if ($offerings->isEmpty())
        {{-- The publish gate refuses a webinar with no active offering, so this
             is only reachable if every option is switched off after publishing.
             Saying so beats an empty box with a dead button. --}}
        <p class="purchase-empty">Booking for this session is not open yet.</p>
    @else
        {{-- A real POST now (P3). Only the selected quantities are sent — no
             price or total — and the server re-derives everything from the
             offering (§14 #1). The Alpine total is display only. --}}
        <form method="POST" action="{{ route('cart.add') }}" class="purchase-form">
            @csrf
        <ul class="purchase-rows">
            @foreach ($offerings as $offering)
                @php
                    $tooltipId = "offering-help-{$offering->id}";
                @endphp

                <li class="purchase-row">
                    <div class="purchase-option">
                        <x-ui.icon name="monitor" :size="18" class="purchase-option-icon" />

                        <label for="qty-{{ $offering->id }}">{{ $offering->label() }}</label>

                        {{-- A focusable button, not a hover-only icon: WCAG 2.1
                             §1.4.13 needs it reachable by keyboard, dismissible
                             with Escape, and hoverable without vanishing. On
                             touch a hover tooltip either does nothing or fires
                             the row. --}}
                        <span
                            class="purchase-help"
                            x-data="tooltip"
                            x-on:keydown.escape.window="close()"
                            x-on:mouseenter="open()"
                            x-on:mouseleave="scheduleClose()"
                        >
                            <button
                                type="button"
                                aria-label="What is included in {{ $offering->label() }}?"
                                aria-describedby="{{ $tooltipId }}"
                                x-on:focus="open()"
                                x-on:blur="close()"
                                x-on:click.prevent="toggle()"
                            >
                                <x-ui.icon name="info" :size="16" />
                            </button>

                            <span class="purchase-tip" id="{{ $tooltipId }}" role="tooltip" x-show="shown" x-cloak>
                                {{ $offering->description() }}
                            </span>
                        </span>
                    </div>

                    <span class="purchase-price">${{ number_format($offering->price_cents / 100, 0) }}</span>

                    <select
                        id="qty-{{ $offering->id }}"
                        name="quantities[{{ $offering->id }}]"
                        class="purchase-qty"
                        x-model.number="quantities[{{ $offering->id }}]"
                        x-on:change="recalculate()"
                    >
                        @for ($i = 0; $i <= $maxQuantity; $i++)
                            <option value="{{ $i }}">{{ $i }}</option>
                        @endfor
                    </select>
                </li>
            @endforeach
        </ul>

        <div class="purchase-total">
            <span>Order Total</span>
            <strong x-text="formattedTotal"></strong>
        </div>

        <button type="submit" class="purchase-cta" x-bind:disabled="total === 0">
            <span x-show="total === 0">Select an Option Above</span>
            <span x-show="total > 0" x-cloak>Add to Cart <span x-text="'– ' + formattedTotal"></span></span>
        </button>
        </form>

        <p class="purchase-trust">
            <span><x-ui.icon name="shield-check" :size="14" /> Secure</span>
            <span><x-ui.icon name="refresh-cw" :size="14" /> 7-Day Refund</span>
            <span><x-ui.icon name="zap" :size="14" /> Instant Access</span>
        </p>
    @endif
</div>
