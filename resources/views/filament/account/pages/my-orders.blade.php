<x-filament-panels::page>
    @php $orders = $this->orders(); @endphp

    @if ($orders->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center">
            <p class="text-gray-500 dark:text-gray-400">You haven't placed any orders yet.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Order</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3 text-right">Invoice</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($orders as $order)
                        <tr>
                            <td class="px-4 py-3 font-semibold text-gray-950 dark:text-white">{{ $order->number }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                {{ optional($order->placed_at)->format('j M Y') ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-gray-100 dark:bg-gray-800 px-2.5 py-0.5 text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ $order->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-950 dark:text-white">
                                {{ \App\Support\Money::format($order->total_cents, $order->currency) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($order->capturedCents() > 0)
                                    <a href="{{ $order->invoiceUrl() }}" class="font-semibold text-primary-600 hover:underline">
                                        Download
                                    </a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
