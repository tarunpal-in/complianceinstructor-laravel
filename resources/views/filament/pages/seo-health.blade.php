@php
    $findings = $this->findings();
    $total = $this->totalIssues();
@endphp

<x-filament-panels::page>
    @if ($total === 0)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-check-circle" class="mt-0.5 h-6 w-6 shrink-0 text-success-600 dark:text-success-400" />
                <div>
                    <h3 class="font-semibold text-gray-950 dark:text-white">Nothing to fix</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Every published webinar, article and page has a usable title and description,
                        no duplicates, and its images have alt text.
                    </p>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                <strong class="text-gray-950 dark:text-white">{{ $total }}</strong>
                {{ Str::plural('thing', $total) }} worth a look, grouped below. None of it stops the site working —
                it is what makes a page easier to find and better to click.
            </p>
        </x-filament::section>

        @foreach ($findings as $check => $items)
            <x-filament::section collapsible>
                <x-slot name="heading">
                    {{ $check }}
                    <x-filament::badge class="ml-1">{{ count($items) }}</x-filament::badge>
                </x-slot>

                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($items as $item)
                        <div class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-950 dark:text-white">{{ $item->subject }}</p>
                                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $item->detail }}</p>
                            </div>
                            @if ($item->url)
                                <x-filament::button tag="a" :href="$item->url" size="xs" color="gray" class="shrink-0">
                                    Fix
                                </x-filament::button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
