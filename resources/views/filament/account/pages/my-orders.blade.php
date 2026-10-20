<x-filament-panels::page>
    @php
        $orders = $this->orders();
        $handoutsByOrder = $this->handoutsByOrder();
    @endphp

    @forelse ($orders as $order)
        @php $handouts = $handoutsByOrder->get($order->getKey()) ?? collect(); @endphp

        <div class="ld-card">
            <div class="ld-order-head">
                <div class="ld-card-main">
                    <h3 class="ld-card-title">Order {{ $order->number }}</h3>
                    <p class="ld-card-speaker">
                        {{ optional($order->placed_at)->format('j F Y') ?? 'Date pending' }}
                        · <span class="ld-order-status">{{ $order->status->label() }}</span>
                    </p>
                </div>

                <div class="ld-order-right">
                    <span class="ld-order-total">{{ \App\Support\Money::format($order->total_cents, $order->currency) }}</span>
                    @if ($order->capturedCents() > 0)
                        <a href="{{ $order->invoiceUrl() }}" class="ld-btn" target="_blank" rel="noopener">Download invoice</a>
                    @endif
                </div>
            </div>

            @if ($order->items->isNotEmpty())
                <ul class="ld-order-items">
                    @foreach ($order->items as $item)
                        <li>
                            <span class="ld-order-item-desc">{{ $item->description }}</span>
                            <span class="ld-order-item-qty">&times;{{ $item->quantity }}</span>
                            <span class="ld-order-item-amt">{{ \App\Support\Money::format($item->line_total_cents, $order->currency) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($handouts->isNotEmpty())
                <div class="ld-handouts">
                    <span class="ld-handouts-label">Study materials</span>
                    <ul class="ld-handouts-list">
                        @foreach ($handouts as $handout)
                            <li>
                                <a href="{{ route('learning.handout', $handout) }}" class="ld-handout" target="_blank" rel="noopener">
                                    <span class="ld-handout-ext">{{ $handout->extension() }}</span>
                                    <span class="ld-handout-name">{{ $handout->displayName() }}</span>
                                    <span class="ld-handout-get">Download</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @empty
        <div class="ld-empty">
            <span class="ld-empty-icon">
                <x-filament::icon icon="heroicon-o-rectangle-stack" class="h-7 w-7" />
            </span>
            <p class="ld-empty-text">You haven't placed any orders yet.</p>
            <a href="{{ route('webinars.index') }}" class="ld-empty-link">Browse webinars</a>
        </div>
    @endforelse
</x-filament-panels::page>
