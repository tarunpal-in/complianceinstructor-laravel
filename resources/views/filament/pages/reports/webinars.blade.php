@php
    $period = $this->getPeriod();
    $rows = $this->getRows();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">{{ $period->describe() }}</x-slot>
        <x-slot name="description">Seats and revenue are for the period chosen. Views and certificates are for all time — a recording sold in March is still being watched in June.</x-slot>

        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing sold, watched or certificated in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Webinar</th>
                            <th class="py-2 pr-4 text-right font-medium">Seats</th>
                            <th class="py-2 pr-4 text-right font-medium">Revenue</th>
                            <th class="py-2 pr-4 text-right font-medium">Views</th>
                            <th class="py-2 pr-4 text-right font-medium">Completed</th>
                            <th class="py-2 text-right font-medium">Certificates</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-2 pr-4 text-gray-950 dark:text-white">
                                    {{ $row->webinar->title }}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        {{ collect([$row->webinar->speaker?->name, $row->webinar->industry?->name])->filter()->implode(' · ') ?: 'No speaker or industry' }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row->seats) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums text-gray-950 dark:text-white">{{ $this->money($row->revenueCents) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row->views) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    @if ($row->completion() === null)
                                        <span class="text-gray-400 dark:text-gray-500">No views</span>
                                    @else
                                        {{ $row->completion() }}%
                                    @endif
                                </td>
                                <td class="py-2 text-right tabular-nums">{{ number_format($row->certificates) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :collapsible="true" :collapsed="true">
        <x-slot name="heading">How these figures are worked out</x-slot>

        <ul class="list-disc space-y-2 pl-5 text-sm text-gray-500 dark:text-gray-400">
            <li>Revenue uses the same definition as the Sales report — money captured inside the period — so the two screens cannot disagree about one month.</li>
            <li><strong>Completion is views finished over views started</strong>, not over seats sold. Somebody who bought a recording and never opened it has not failed to finish it.</li>
            <li>Only recorded viewing is tracked. Live attendance needs the <code>attendance</code> table from §5.8, which has not been built.</li>
            <li>Revoked certificates are not counted.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
