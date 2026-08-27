@props(['speakers' => []])

<section class="bg-white py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid gap-12 lg:grid-cols-12 lg:items-center lg:gap-14">

            <div class="lg:col-span-5">
                <x-site.section-heading
                    align="start"
                    eyebrow="Our Instructors"
                    title="Experienced Faculty"
                />

                <p class="mt-4 max-w-lg text-base leading-relaxed text-navy-600">
                    Our speakers have argued the cases, run the audits and built the programs.
                    They bring the edge cases you won't find in a compliance handbook, and they
                    stay on the line until your question is answered.
                </p>

                <a href="#" class="mt-7 inline-flex items-center gap-x-2 rounded-full bg-gold-500 px-6 py-3 text-sm font-semibold text-navy-950 transition hover:bg-gold-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-600">
                    Meet the Speakers
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.6l-3.1-3.1a.75.75 0 1 1 1.06-1.06l4.38 4.38a.75.75 0 0 1 0 1.06l-4.38 4.38a.75.75 0 1 1-1.06-1.06l3.1-3.1H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd" /></svg>
                </a>
            </div>

            {{-- The comp staggers the right-hand column downward. That offset is
                 desktop-only; stacked cards must not inherit it. --}}
            <ul class="grid grid-cols-2 gap-4 sm:gap-5 lg:col-span-7">
                @foreach ($speakers as $i => $speaker)
                    <li @class([
                        'group relative overflow-hidden rounded-2xl border border-cream-300 bg-cream-50 transition hover:border-gold-300 hover:shadow-lg',
                        'lg:translate-y-8' => $i % 2 === 1,
                    ])>
                        <x-site.image-placeholder
                            class="aspect-4/5 w-full"
                            :label="$speaker['name']"
                            :seed="$i"
                        />

                        <div class="p-4">
                            <h3 class="text-sm font-bold tracking-tight text-navy-950">
                                <a href="#" class="before:absolute before:inset-0">{{ $speaker['name'] }}</a>
                            </h3>
                            <p class="mt-1 text-[11px] leading-snug text-navy-600">{{ $speaker['credentials'] }}</p>
                            <p class="mt-2 text-[10px] font-bold uppercase tracking-wider text-gold-700">{{ $speaker['bodies'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
