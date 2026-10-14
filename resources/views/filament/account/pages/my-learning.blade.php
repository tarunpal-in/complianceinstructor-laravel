<x-filament-panels::page>
    @php $rows = $this->webinars(); @endphp

    @if ($rows->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center">
            <p class="text-gray-500 dark:text-gray-400">You don't have access to any webinars yet.</p>
            <a href="{{ route('home') }}" class="mt-3 inline-block font-semibold text-primary-600 hover:underline">
                Browse webinars
            </a>
        </div>
    @else
        <div class="grid gap-4">
            @foreach ($rows as $row)
                @php
                    $webinar = $row['webinar'];
                    $assetsByFormat = $webinar->assets->keyBy(fn ($a) => $a->format->value);
                @endphp
                <div class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $webinar->title }}</h3>
                            @if ($webinar->speaker)
                                <p class="text-sm text-gray-500 dark:text-gray-400">with {{ $webinar->speaker->name }}</p>
                            @endif
                            @if ($webinar->scheduled_at)
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $webinar->scheduled_at->format('j M Y, g:ia') }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($row['formats'] as $format)
                            @php $asset = $assetsByFormat->get($format->value); @endphp
                            @if ($asset && $asset->isReleased())
                                <a href="{{ route('learning.access', [$webinar->slug, $format->value]) }}"
                                   @if ($format === \App\Enums\Format::Live) target="_blank" rel="noopener" @endif
                                   class="fi-btn inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-500">
                                    {{ $format === \App\Enums\Format::Live ? 'Join session' : 'Open '.$format->label() }}
                                </a>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $format->label() }} —
                                    @if ($asset && $asset->available_from)
                                        available {{ $asset->available_from->format('j M Y') }}
                                    @else
                                        not available yet
                                    @endif
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
