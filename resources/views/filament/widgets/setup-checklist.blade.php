@php
    $items = $this->items();
    $completed = $this->completed();
    $total = $this->total();
@endphp

<x-filament-widgets::widget>
    <section class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 px-6 py-4 dark:border-white/10">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Getting started</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    What is still outstanding before launch. This card disappears once every item is done.
                </p>
            </div>

            <div class="flex items-center gap-x-3">
                <button
                    type="button"
                    wire:click="dismiss"
                    wire:confirm="Hide the setup checklist? You can bring it back from System → Settings."
                    class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200"
                >
                    Skip
                </button>

                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ $completed }} of {{ $total }}
                </span>
                {{-- Progress is announced to assistive tech; the bar itself is decorative. --}}
                <div
                    class="h-2 w-32 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"
                    role="progressbar"
                    aria-valuenow="{{ $completed }}"
                    aria-valuemin="0"
                    aria-valuemax="{{ $total }}"
                    aria-label="Setup progress"
                >
                    <div class="h-full rounded-full bg-amber-400 transition-all" style="width: {{ $this->percentage() }}%"></div>
                </div>
            </div>
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

                    @unless ($item['done'])
                        <a
                            href="{{ $item['url'] }}"
                            class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold text-primary-600 transition hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-400/10"
                        >
                            Set up
                            <span class="sr-only">{{ $item['label'] }}</span>
                        </a>
                    @endunless
                </li>
            @endforeach
        </ul>
    </section>
</x-filament-widgets::widget>
