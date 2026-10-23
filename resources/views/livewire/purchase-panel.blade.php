<div class="purchase-panel">
    <h2 class="purchase-head">
        <x-ui.icon name="shopping-cart" :size="20" />
        {{ $hasMembership ? 'Access this webinar' : 'Purchase Options' }}
    </h2>

    {{-- Redemption feedback, flashed by the membership.watch route on redirect. --}}
    @if (session('membershipStatus'))
        <p class="mp-flash mp-flash-ok" role="status">{{ session('membershipStatus') }}</p>
    @endif
    @if (session('membershipError'))
        <p class="mp-flash mp-flash-error" role="alert">{{ session('membershipError') }}</p>
    @endif

    @if ($buyOfferings->isEmpty() && ! $hasMembership)
        <p class="purchase-empty">Booking for this session is not open yet.</p>
    @else
        {{-- Included with the member's plan: open it, or redeem an allowance. --}}
        @if ($hasMembership)
            <div class="access-included">
                <p class="access-section-head">Included with your membership</p>
                <ul class="access-rows">
                    @foreach ($included as $row)
                        @php $format = $row['format']; @endphp
                        <li class="access-row" wire:key="included-{{ $row['offering']->id }}">
                            <span class="access-option">
                                <x-ui.icon :name="$row['offering']->iconName()" :size="18" class="purchase-option-icon" />
                                {{ $row['offering']->label() }}
                            </span>

                            @if ($row['state'] === 'held')
                                <a class="access-open"
                                   href="{{ route('learning.access', [$webinar->slug, $format->value]) }}"
                                   @if ($format === \App\Enums\Format::Live) target="_blank" rel="noopener" @endif>
                                    {{ $format === \App\Enums\Format::Live ? 'Join session' : 'Open' }}
                                </a>
                            @elseif ($row['state'] === 'spendable')
                                <form method="POST" action="{{ route('membership.watch', $webinar->slug) }}" class="access-redeem-form">
                                    @csrf
                                    <input type="hidden" name="format" value="{{ $format->value }}">
                                    <button type="submit" class="access-redeem">Add to my account</button>
                                    <span class="access-remaining">{{ $row['remaining'] }}</span>
                                </form>
                            @else
                                <span class="access-exhausted">Allowance used this period</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Everything bought: bundles, and any format the plan does not cover. --}}
        @if ($buyOfferings->isNotEmpty())
            @if ($hasMembership)
                <p class="access-section-head access-buy-head">Or buy a different option</p>
            @endif

            <form wire:submit="add" class="purchase-form">
                <ul class="purchase-rows">
                    @foreach ($buyOfferings as $offering)
                        @php $tooltipId = "offering-help-{$offering->id}"; @endphp

                        <li class="purchase-row" wire:key="offering-{{ $offering->id }}">
                            <div class="purchase-option">
                                <x-ui.icon :name="$offering->iconName()" :size="18" class="purchase-option-icon" />

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
        @endif

        <p class="purchase-trust">
            <span><x-ui.icon name="shield-check" :size="14" /> Secure</span>
            <span><x-ui.icon name="refresh-cw" :size="14" /> 7-Day Refund</span>
            <span><x-ui.icon name="zap" :size="14" /> Instant Access</span>
        </p>
    @endif
</div>
