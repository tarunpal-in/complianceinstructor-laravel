<div class="purchase-panel">
    <h2 class="purchase-head">
        <x-ui.icon name="shopping-cart" :size="20" />
        Purchase Options
    </h2>

    @if ($offerings->isEmpty())
        <p class="purchase-empty">Booking for this session is not open yet.</p>
    @else
        <form wire:submit="add" class="purchase-form">
            <ul class="purchase-rows">
                @foreach ($offerings as $offering)
                    @php $tooltipId = "offering-help-{$offering->id}"; @endphp

                    <li class="purchase-row" wire:key="offering-{{ $offering->id }}">
                        <div class="purchase-option">
                            <x-ui.icon name="monitor" :size="18" class="purchase-option-icon" />

                            <label for="qty-{{ $offering->id }}">{{ $offering->label() }}</label>

                            {{-- Tooltip stays pure Alpine (keyboard + hover, WCAG 1.4.13). --}}
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

                        <select id="qty-{{ $offering->id }}" class="purchase-qty" wire:model.live="quantities.{{ $offering->id }}">
                            @for ($i = 0; $i <= $maxQuantity; $i++)
                                <option value="{{ $i }}">{{ $i }}</option>
                            @endfor
                        </select>
                    </li>
                @endforeach
            </ul>

            <div class="purchase-total">
                <span>Order Total</span>
                <strong>{{ $formattedTotal }}</strong>
            </div>

            <button type="submit" class="purchase-cta" @disabled($this->totalCents() === 0)
                    wire:loading.attr="disabled" wire:target="add">
                @if ($this->totalCents() === 0)
                    <span>Select an Option Above</span>
                @else
                    <span wire:loading.remove wire:target="add">Add to Cart – {{ $formattedTotal }}</span>
                    <span wire:loading wire:target="add">Adding…</span>
                @endif
            </button>
        </form>

        <p class="purchase-trust">
            <span><x-ui.icon name="shield-check" :size="14" /> Secure</span>
            <span><x-ui.icon name="refresh-cw" :size="14" /> 7-Day Refund</span>
            <span><x-ui.icon name="zap" :size="14" /> Instant Access</span>
        </p>
    @endif
</div>
