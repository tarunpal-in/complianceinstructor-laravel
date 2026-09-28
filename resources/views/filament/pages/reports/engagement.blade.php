@php
    $period = $this->getPeriod();
    $report = $this->getReport();
    $signups = $this->getSignupsBySource();
    $inquiries = $this->getInquiriesBySource();
    $net = $report->netListChange();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($report->isQuiet())
        <x-filament::section>
            <x-slot name="heading">{{ $period->describe() }}</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nobody joined the mailing list, nobody got in touch, and nothing was published or commented on.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Mailing list</x-slot>
        <x-slot name="description">The list size is as of now. Joined and left cover {{ strtolower($period->describe()) }}.</x-slot>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">On the list</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report->listSize()) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Addresses a campaign may go to</p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Joined</p>
                <p class="mt-1 text-2xl font-semibold text-success-600 dark:text-success-400">{{ number_format($report->joined()) }}</p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Left</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report->left()) }}</p>
                <p class="mt-1 text-xs @class(['text-danger-600 dark:text-danger-400' => $net < 0, 'text-gray-500 dark:text-gray-400' => $net >= 0])">
                    Net {{ $net >= 0 ? '+' : '' }}{{ number_format($net) }}
                </p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Problems</p>
                <p class="mt-1 text-2xl font-semibold @class(['text-danger-600 dark:text-danger-400' => $report->deliverabilityProblems() > 0, 'text-gray-950 dark:text-white' => $report->deliverabilityProblems() === 0])">
                    {{ number_format($report->deliverabilityProblems()) }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Bouncing or reported as spam</p>
            </div>
        </div>

        @if ($signups->isNotEmpty())
            <x-slot name="footer">
                <ul class="flex flex-wrap gap-2 text-xs">
                    @foreach ($signups as $row)
                        <x-filament::badge>{{ $row->label }}: {{ number_format($row->cents) }}</x-filament::badge>
                    @endforeach
                </ul>
            </x-slot>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Inbox</x-slot>
        <x-slot name="description">How long people wait is the one figure here that changes what anybody does.</x-slot>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Received</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report->inquiriesReceived()) }}</p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Answered</p>
                <p class="mt-1 text-2xl font-semibold text-success-600 dark:text-success-400">{{ number_format($report->inquiriesAnswered()) }}</p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Typical wait</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->describeWait() }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Median, not average</p>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Still waiting</p>
                <p class="mt-1 text-2xl font-semibold @class(['text-danger-600 dark:text-danger-400' => $report->inquiriesStillWaiting() > 0, 'text-gray-950 dark:text-white' => $report->inquiriesStillWaiting() === 0])">
                    {{ number_format($report->inquiriesStillWaiting()) }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Right now, whenever they arrived</p>
            </div>
        </div>

        @if ($inquiries->isNotEmpty())
            <x-slot name="footer">
                <ul class="flex flex-wrap gap-2 text-xs">
                    @foreach ($inquiries as $row)
                        <x-filament::badge>{{ $row->label }}: {{ number_format($row->cents) }}</x-filament::badge>
                    @endforeach
                </ul>
            </x-slot>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Blog</x-slot>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Articles published', $report->postsPublished(), null],
                ['Comments received', $report->commentsReceived(), null],
                ['Comments published', $report->commentsPublished(), null],
                ['Comments waiting', $report->commentsWaiting(), 'Right now, whenever they arrived'],
            ] as [$label, $value, $hint])
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($value) }}</p>
                    @if ($hint)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :collapsible="true" :collapsed="true">
        <x-slot name="heading">How these figures are worked out</x-slot>

        <ul class="list-disc space-y-2 pl-5 text-sm text-gray-500 dark:text-gray-400">
            <li><strong>The wait is the median, not the average.</strong> One inquiry answered after three weeks would drag an average into meaninglessness; the median is what most people actually experienced.</li>
            <li>List size, deliverability problems, inquiries still waiting and comments waiting are all <strong>as of now</strong>, not counts of the period — a problem that appeared last month has not gone away because the month has.</li>
            <li>"Joined" counts the date somebody subscribed, which for a re-subscribing address is the latest time they asked, not the first.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
