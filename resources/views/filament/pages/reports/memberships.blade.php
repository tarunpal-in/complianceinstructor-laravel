@php
    $period = $this->getPeriod();
    $report = $this->getReport();
    $byPlan = $this->getMrrByPlan();
    $renewals = $this->getRenewalsDue();
    $nearing = $this->getNearingLimit();
    $churn = $report->churnPercent();
    $largest = max(1, (int) $byPlan->max->cents);
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">Where it stands today</x-slot>
        <x-slot name="description">These four are as of now. The three below them cover {{ strtolower($period->describe()) }}.</x-slot>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Active members', number_format($report->activeMembers()), 'Being paid for right now'],
                ['MRR', $this->money($report->mrrCents()), 'Term prices normalised to a month'],
                ['ARR', $this->money($report->arrCents()), 'MRR over twelve months'],
                ['Per member', $this->money($report->averageMrrCents()), 'Average monthly value'],
            ] as [$label, $value, $hint])
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Started</p>
                <p class="mt-1 text-2xl font-semibold text-success-600 dark:text-success-400">{{ number_format($report->started()) }}</p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Cancelled</p>
                <p class="mt-1 text-2xl font-semibold text-danger-600 dark:text-danger-400">{{ number_format($report->cancelled()) }}</p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Churn</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ $churn === null ? 'Not yet' : $churn.'%' }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $churn === null ? 'Nobody to lose at the start of this period' : 'Of the members there were to lose' }}
                </p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">MRR by plan</x-slot>

        @if ($byPlan->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No plan is producing recurring revenue yet.</p>
        @else
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($byPlan as $row)
                        <tr>
                            <td class="w-1/3 py-2 pr-4 align-middle text-gray-950 dark:text-white">
                                {{ $row->label }}
                                <span class="text-gray-500 dark:text-gray-400">· {{ $row->note }}</span>
                            </td>
                            <td class="py-2 pr-4 align-middle">
                                <div class="h-2 w-full rounded-full bg-gray-100 dark:bg-white/10">
                                    <div class="h-2 rounded-full bg-primary-500" style="width: {{ $row->shareOf($largest) }}%"></div>
                                </div>
                            </td>
                            <td class="w-32 py-2 text-right align-middle tabular-nums text-gray-950 dark:text-white">{{ $this->money($row->cents) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Renewing in the next 30 days</x-slot>
        <x-slot name="description">A renewal is a conversation. One nobody had is a cancellation that did not need to happen.</x-slot>

        @if ($renewals->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing renews in the next 30 days.</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($renewals as $subscription)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <span class="text-gray-950 dark:text-white">{{ $subscription->member?->name ?? 'Deleted account' }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $subscription->plan?->name }}</span>
                        <span class="ml-auto tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $subscription->term_ends_at?->format('j M Y') }}
                            ({{ $subscription->term_ends_at?->diffForHumans() }})
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section :collapsible="true" :collapsed="$nearing->isEmpty()">
        <x-slot name="heading">Running out of allowance</x-slot>
        <x-slot name="description">Members who have used 80% or more of a format's allowance. Unlimited allowances are not listed — there is nothing to run out of.</x-slot>

        @if ($nearing->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nobody is close to a limit.</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($nearing as $row)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <span class="text-gray-950 dark:text-white">{{ $row['subscription']->member?->name ?? 'Deleted account' }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $row['subscription']->plan?->name }}</span>
                        <x-filament::badge :color="$row['remaining'] === 0 ? 'danger' : 'warning'">
                            {{ $row['format'] }}: {{ $row['remaining'] === 0 ? 'none left' : $row['remaining'].' left' }}
                        </x-filament::badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section :collapsible="true" :collapsed="true">
        <x-slot name="heading">How these figures are worked out</x-slot>

        <ul class="list-disc space-y-2 pl-5 text-sm text-gray-500 dark:text-gray-400">
            <li><strong>MRR is normalised, not summed.</strong> A yearly membership at $1,200 counts as $100 a month. Adding raw term prices would make every annual renewal look like a spike and every month without one look like a collapse.</li>
            <li>One-off plans are excluded from MRR. They are revenue and the Sales report counts them, but they are not recurring.</li>
            <li>Each subscription uses the price it was <strong>sold at</strong>, not the plan's current price — members keep what they signed up for.</li>
            <li><strong>Churn is people, not money</strong>: cancellations in the period over the members active at the start of it. Revenue churn is a different number with a different denominator.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
