@php
    $period = $this->getPeriod();
    $headline = $this->getHeadline();
    $before = $this->getComparison();
    $breakdowns = $this->getBreakdowns();

    $figures = [
        ['Net', 'net', 'The money kept: captured less refunded.'],
        ['Gross', 'gross', 'Everything captured in the period.'],
        ['Refunded', 'refunded', 'Given back in the period.'],
        ['Orders', 'orders', 'Orders with money captured against them.'],
        ['Average order', 'average', 'Net over orders.'],
        ['Discount given', 'discount', 'What was not charged.'],
    ];
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">{{ $period->describe() }}</x-slot>
        <x-slot name="description">Compared with {{ strtolower($period->previous()->describe()) }}.</x-slot>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($figures as [$label, $key, $hint])
                @php
                    $isCount = $key === 'orders';
                    $change = $this->change($headline[$key], $before[$key] ?? 0);
                    // A refund going up is not good news, so that one card
                    // reads its arrow the other way round.
                    $goodWhenUp = $key !== 'refunded';
                @endphp

                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>

                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                        {{ $isCount ? number_format($headline[$key]) : $this->money($headline[$key]) }}
                    </p>

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if ($change === null)
                            {{ $hint }}
                        @else
                            <span @class([
                                'font-medium',
                                'text-success-600 dark:text-success-400' => ($change >= 0) === $goodWhenUp,
                                'text-danger-600 dark:text-danger-400' => ($change >= 0) !== $goodWhenUp,
                            ])>
                                {{ $change >= 0 ? '+' : '' }}{{ $change }}%
                            </span>
                            against {{ $isCount ? number_format($before[$key]) : $this->money($before[$key]) }}
                        @endif
                    </p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    @foreach ($breakdowns as $heading => $rows)
        @php
            // The bar is scaled to the largest row, not to the total, so a
            // breakdown with one dominant row still shows shape in the rest.
            $largest = max(1, (int) collect($rows)->max->cents);
        @endphp

        <x-filament::section :collapsible="true" :collapsed="! $loop->first">
            <x-slot name="heading">{{ $heading }}</x-slot>

            @if (count($rows) === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">Nothing in this period.</p>
            @else
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="w-1/3 py-2 pr-4 align-middle text-gray-950 dark:text-white">
                                    {{ $row->label }}
                                    @if ($row->note)
                                        <span class="text-gray-500 dark:text-gray-400">· {{ $row->note }}</span>
                                    @endif
                                </td>

                                <td class="py-2 pr-4 align-middle">
                                    {{-- Plain CSS width rather than a chart
                                         library: one number per row, and it has
                                         to read the same in both themes. --}}
                                    <div class="h-2 w-full rounded-full bg-gray-100 dark:bg-white/10">
                                        <div class="h-2 rounded-full bg-primary-500"
                                             style="width: {{ $row->shareOf($largest) }}%"></div>
                                    </div>
                                </td>

                                <td class="w-32 py-2 text-right align-middle tabular-nums text-gray-950 dark:text-white">
                                    {{ $this->money($row->cents) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endforeach

    <x-filament::section :collapsible="true" :collapsed="true">
        <x-slot name="heading">How these figures are worked out</x-slot>

        <ul class="list-disc space-y-2 pl-5 text-sm text-gray-500 dark:text-gray-400">
            <li>Revenue is counted on the date the money was <strong>captured</strong>, not the date the order was placed. An order placed on the 30th and paid on the 2nd belongs to the new month.</li>
            <li>A refund reduces the period it <strong>happened in</strong>, not the period of the original sale, so a month that has already been reported does not change afterwards.</li>
            <li>Where one order line sells several formats, its revenue is <strong>split evenly</strong> between them. Counting it fully against each would make the format breakdown add up to more than the revenue.</li>
            <li>Dates use the business timezone ({{ \App\Support\Period::timezone()->getName() }}), not the server's.</li>
            <li>Nothing here is pre-calculated. Every figure is read from the payments and refunds each time the page loads.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
