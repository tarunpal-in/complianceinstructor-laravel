@php
    $items = $this->checklistItems();
    $completed = $this->checklistCompleted();
    $total = $this->checklistTotal();
    $hidden = $this->checklistIsHidden();
@endphp

<x-filament-panels::page>
    <section class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 px-6 py-4 dark:border-white/10">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Setup checklist</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    {{ $completed }} of {{ $total }} done.
                    {{ $hidden ? 'Hidden from your dashboard.' : 'Showing on your dashboard until every item is done.' }}
                </p>
            </div>

            <x-filament::button
                :color="$hidden ? 'primary' : 'gray'"
                wire:click="toggleChecklist"
                size="sm"
            >
                {{ $hidden ? 'Show on dashboard' : 'Hide from dashboard' }}
            </x-filament::button>
        </header>

        <ul class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($items as $item)
                <li class="flex items-start gap-x-3 px-6 py-3.5">
                    @if ($item['done'])
                        <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-green-100 text-green-600 dark:bg-green-500/20 dark:text-green-400">
                            <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
                            <span class="sr-only">Done:</span>
                        </span>
                    @else
                        <span class="mt-0.5 size-5 shrink-0 rounded-full border-2 border-dashed border-gray-300 dark:border-white/20">
                            <span class="sr-only">Outstanding:</span>
                        </span>
                    @endif

                    <div class="min-w-0 flex-1">
                        <p @class([
                            'text-sm font-medium',
                            'text-gray-400 line-through dark:text-gray-500' => $item['done'],
                            'text-gray-950 dark:text-white' => ! $item['done'],
                        ])>{{ $item['label'] }}</p>

                        @unless ($item['done'])
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item['description'] }}</p>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- CLAUDE.md §5.6 — brand, contact, social, promo bar, trust figures and
         SEO defaults become tabs here once the `settings` table lands. --}}
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Brand, contact details, social links and the promo bar arrive on this page with the settings module.
    </p>
</x-filament-panels::page>
