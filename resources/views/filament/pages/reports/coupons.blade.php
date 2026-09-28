@php
    $period = $this->getPeriod();
    $report = $this->getReport();
    $rows = $this->getRows();
    $neverUsed = $this->getNeverUsed();
    $endingSoon = $this->getEndingSoon();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">{{ $period->describe() }}</x-slot>
        <x-slot name="description">Codes redeemed in this period. The revenue beside each one is what has been captured on those orders, whenever it was captured.</x-slot>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Given away</p>
                <p class="mt-1 text-2xl font-semibold text-danger-600 dark:text-danger-400">{{ $this->money($report->totalDiscountCents()) }}</p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">On orders worth</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->money($report->totalRevenueCents()) }}</p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Codes used</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report->totalUses()) }}</p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">By code</x-slot>

        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No code was used in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Code</th>
                            <th class="py-2 pr-4 text-right font-medium">Uses</th>
                            <th class="py-2 pr-4 text-right font-medium">Given away</th>
                            <th class="py-2 pr-4 text-right font-medium">Order value</th>
                            <th class="py-2 pr-4 text-right font-medium">Discount rate</th>
                            <th class="py-2 text-right font-medium">Cap used</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-2 pr-4">
                                    <span class="font-mono text-gray-950 dark:text-white">{{ $row->coupon->code }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row->coupon->describeDiscount() }}</span>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row->uses) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums text-danger-600 dark:text-danger-400">{{ $this->money($row->discountCents) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums text-gray-950 dark:text-white">{{ $this->money($row->revenueCents) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ $row->discountRate() === null ? 'Not known' : $row->discountRate().'%' }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    @if ($row->capUsed() === null)
                                        <span class="text-gray-400 dark:text-gray-500">No cap</span>
                                    @else
                                        <x-filament::badge :color="$row->capUsed() >= 90 ? 'danger' : 'gray'">
                                            {{ $row->capUsed() }}%
                                        </x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if ($endingSoon->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Ending in the next 30 days</x-slot>
            <x-slot name="description">A code still being handed out after it expires is a support conversation nobody wants.</x-slot>

            <ul class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($endingSoon as $coupon)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <span class="font-mono text-gray-950 dark:text-white">{{ $coupon->code }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $coupon->describeDiscount() }}</span>
                        <span class="ml-auto tabular-nums text-gray-500 dark:text-gray-400">
                            ends {{ $coupon->ends_at?->format('j M Y') }} ({{ $coupon->ends_at?->diffForHumans() }})
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section :collapsible="true" :collapsed="true">
        <x-slot name="heading">Live codes nobody has used ({{ $neverUsed->count() }})</x-slot>
        <x-slot name="description">Not a failure on its own — a code printed on a conference flyer is meant to sit unused until somebody types it.</x-slot>

        @if ($neverUsed->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Every live code has been used at least once.</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($neverUsed as $coupon)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <span class="font-mono text-gray-950 dark:text-white">{{ $coupon->code }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $coupon->describeDiscount() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section :collapsible="true" :collapsed="true">
        <x-slot name="heading">How these figures are worked out</x-slot>

        <ul class="list-disc space-y-2 pl-5 text-sm text-gray-500 dark:text-gray-400">
            <li><strong>This does not say a coupon produced a sale.</strong> Nothing here can tell you whether those people would have bought anyway. "Order value" is what those orders were worth; "given away" is what it cost to get them.</li>
            <li>Redemptions are counted by <strong>when they were redeemed</strong>. The revenue beside them is whatever has been captured on those orders, which may be a later month.</li>
            <li>Revenue uses the same definition as the Sales report — succeeded payments — so the two screens cannot disagree about one order.</li>
            <li>Discount rate is the discount as a share of what the order would have been worth without it.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
