@props(['webinars' => []])

<section class="bg-cream-50 py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <x-site.section-heading
                align="start"
                eyebrow="Live Events"
                title="Upcoming Webinars"
                subtitle="Expert-led sessions with recognized CEU credits. Register today."
            />

            <a href="#" class="group inline-flex shrink-0 items-center gap-x-1.5 text-sm font-semibold text-navy-800 transition hover:text-gold-700">
                All Upcoming Webinars
                <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.6l-3.1-3.1a.75.75 0 1 1 1.06-1.06l4.38 4.38a.75.75 0 0 1 0 1.06l-4.38 4.38a.75.75 0 1 1-1.06-1.06l3.1-3.1H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd" /></svg>
            </a>
        </div>

        <ul class="mt-10 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($webinars as $i => $webinar)
                @php $starts = $webinar['starts_at']; @endphp

                {{-- `relative` anchors the stretched link on the title, which makes
                     the whole card clickable while keeping one link per card. --}}
                <li class="group relative flex flex-col overflow-hidden rounded-2xl border border-cream-300 bg-white transition hover:-translate-y-0.5 hover:border-navy-200 hover:shadow-lg">
                    <div class="relative">
                        <x-site.image-placeholder
                            class="aspect-16/10 w-full"
                            :label="$webinar['title']"
                            :seed="$i + 1"
                        />

                        {{-- Badge and body date both derive from one Carbon instance.
                             The comp shows '26 AUG' on a card whose body reads
                             'Aug 22' — two fields drifting is exactly what this
                             avoids. --}}
                        <div class="absolute start-3 top-3 overflow-hidden rounded-lg bg-white text-center shadow-md">
                            <span class="block px-2.5 pt-1.5 text-lg font-bold leading-none text-navy-950">{{ $starts->format('d') }}</span>
                            <span class="mt-0.5 block bg-navy-900 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white">{{ $starts->format('M') }}</span>
                        </div>

                        <span class="absolute end-3 top-3 inline-flex items-center gap-x-1.5 rounded-full bg-red-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white shadow-sm">
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-white" aria-hidden="true"></span>
                            Live Webinar
                        </span>
                    </div>

                    <div class="flex flex-1 flex-col p-5">
                        <ul class="flex flex-wrap gap-1.5" aria-label="Accreditations">
                            @foreach ($webinar['accreditations'] as $code)
                                <li class="rounded border border-navy-200 bg-navy-50 px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-navy-700">{{ $code }}</li>
                            @endforeach
                        </ul>

                        <h3 class="mt-3 text-[15px] font-bold leading-snug tracking-tight text-navy-950">
                            <a href="#" class="transition before:absolute before:inset-0 hover:text-navy-700">{{ $webinar['title'] }}</a>
                        </h3>

                        <p class="mt-2.5 flex items-start gap-x-1.5 text-xs text-navy-600">
                            <svg class="mt-px h-3.5 w-3.5 shrink-0 text-navy-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M8 3v4M16 3v4M3 11h18" stroke-linecap="round" /></svg>
                            <time datetime="{{ $starts->toIso8601String() }}">{{ $starts->format('M j, Y') }} {{ $webinar['time_label'] }}</time>
                        </p>

                        <p class="mt-1.5 flex items-center gap-x-1.5 text-xs text-navy-600">
                            <svg class="h-3.5 w-3.5 shrink-0 text-navy-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" stroke-linecap="round" /></svg>
                            {{ $webinar['duration'] }}
                        </p>

                        <div class="mt-4 flex items-center gap-x-2.5 border-t border-cream-200 pt-4">
                            <x-site.image-placeholder class="h-9 w-9 shrink-0 rounded-full" :label="$webinar['speaker']" :seed="$i + 3" />
                            <div class="min-w-0">
                                <p class="truncate text-xs font-semibold text-navy-950">{{ $webinar['speaker'] }}</p>
                                <p class="truncate text-[11px] text-navy-500">{{ $webinar['speaker_title'] }}</p>
                            </div>
                        </div>

                        <span class="mt-4 block rounded-full border border-navy-200 py-2.5 text-center text-xs font-semibold text-navy-800 transition group-hover:border-gold-400 group-hover:bg-gold-500 group-hover:text-navy-950">
                            View Details
                        </span>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>
