@php
    $period = $this->getPeriod();
    $summary = $this->getSummary();
    $awarded = $this->getAwarded();
    $revoked = $this->getRevoked();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">{{ $period->describe() }}</x-slot>
        <x-slot name="description">Credits awarded and still standing. Revoked certificates are excluded from these figures and listed at the foot of the page.</x-slot>

        @if ($summary === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">No credits were awarded in this period.</p>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($summary as $body)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $body->code }}</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $body->credits }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            credit hours ·
                            {{ trans_choice(':count certificate|:count certificates', $body->certificates, ['count' => $body->certificates]) }} ·
                            {{ trans_choice(':count learner|:count learners', $body->learners, ['count' => $body->learners]) }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    @if ($awarded->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">What was awarded</x-slot>
            <x-slot name="description">Read from each certificate's own snapshot, so a webinar renamed since does not change what was reported.</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Body</th>
                            <th class="py-2 pr-4 font-medium">Learner</th>
                            <th class="py-2 pr-4 font-medium">Activity</th>
                            <th class="py-2 pr-4 text-right font-medium">Hours</th>
                            <th class="py-2 pr-4 font-medium">Awarded</th>
                            <th class="py-2 font-medium">Certificate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($awarded as $certificate)
                            <tr>
                                <td class="py-2 pr-4"><x-filament::badge>{{ $certificate->accreditation_code }}</x-filament::badge></td>
                                <td class="py-2 pr-4 text-gray-950 dark:text-white">
                                    {{ $certificate->learner_name }}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $certificate->user?->email }}</span>
                                </td>
                                <td class="py-2 pr-4">{{ $certificate->webinar_title }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $certificate->credits }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $certificate->issued_at?->format('j M Y') }}</td>
                                <td class="py-2 font-mono text-xs">{{ $certificate->serial }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    @if ($revoked->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Withdrawn in this period</x-slot>
            <x-slot name="description">Listed by the date they were revoked, not issued. A body that received one of these in an earlier submission needs telling.</x-slot>

            <ul class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($revoked as $certificate)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <x-filament::badge color="danger">{{ $certificate->accreditation_code }}</x-filament::badge>
                        <span class="text-gray-950 dark:text-white">{{ $certificate->learner_name }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $certificate->webinar_title }}</span>
                        <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $certificate->serial }}</span>
                        <span class="text-gray-500 dark:text-gray-400">· {{ $certificate->revoked_reason ?? 'No reason recorded' }}</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section :collapsible="true" :collapsed="true">
        <x-slot name="heading">Before you submit this</x-slot>

        <ul class="list-disc space-y-2 pl-5 text-sm text-gray-500 dark:text-gray-400">
            <li>The export columns are a sensible default, <strong>not a body's prescribed layout</strong>. HRCI and SHRM each specify their own; give us the template and the columns change in one place.</li>
            <li>Revoked certificates are excluded from the figures above, because a withdrawn credit was not awarded.</li>
            <li>Every value comes from the certificate's own snapshot — the learner's name, the activity title, the hours and the body were all frozen when it was issued.</li>
            <li>Dates use the business timezone ({{ \App\Support\Period::timezone()->getName() }}).</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
