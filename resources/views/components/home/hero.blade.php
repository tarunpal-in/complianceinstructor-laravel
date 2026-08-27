@props(['stats' => [], 'bodies' => []])

<section class="relative overflow-hidden bg-cream-100">
    <div class="pointer-events-none absolute -start-10 bottom-0 h-64 w-64 bg-dot-field text-navy-900/10" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
        <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-12 xl:gap-16">

            {{-- Copy --}}
            <div>
                <p class="inline-flex items-center gap-x-2 rounded-full border border-gold-300 bg-gold-100/70 px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider text-gold-800">
                    <span class="h-1.5 w-1.5 rounded-full bg-gold-500" aria-hidden="true"></span>
                    #1 Compliance Training Platform
                </p>

                <h1 class="mt-5 text-4xl font-bold leading-[1.1] tracking-tight text-navy-950 sm:text-5xl lg:text-[56px] lg:leading-[1.08]">
                    Stay Ahead — Learn from<br class="hidden sm:block">
                    <span class="text-navy-900">the Best.</span>
                </h1>

                <p class="mt-5 max-w-xl text-base leading-relaxed text-navy-700 lg:text-[17px]">
                    Access live and recorded compliance webinars led by certified industry experts.
                    Earn HRCI, SHRM, and NASBA/CPE recertification credits — complete with study
                    materials, certificates, and transcripts.
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <a href="#" class="inline-flex items-center justify-center gap-x-2 rounded-full bg-gold-500 px-7 py-3.5 text-sm font-semibold text-navy-950 shadow-sm transition hover:bg-gold-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-600">
                        Start Learning Now
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.6l-3.1-3.1a.75.75 0 1 1 1.06-1.06l4.38 4.38a.75.75 0 0 1 0 1.06l-4.38 4.38a.75.75 0 1 1-1.06-1.06l3.1-3.1H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd" /></svg>
                    </a>

                    <a href="#" class="inline-flex items-center justify-center rounded-full border border-navy-300 bg-white px-7 py-3.5 text-sm font-semibold text-navy-900 transition hover:border-navy-400 hover:bg-navy-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-600">
                        View Courses
                    </a>
                </div>

                {{-- Trust figures. §5.6 `settings` owns these in P6 — they must be
                     editable, and the comp's own 4.9 here vs 4.8 in testimonials
                     shows what happens when they are hardcoded in two places. --}}
                <dl class="mt-10 grid max-w-lg grid-cols-3 gap-4 border-t border-cream-300 pt-6">
                    @foreach ($stats as $stat)
                        <div>
                            <dt class="sr-only">{{ $stat['label'] }}</dt>
                            <dd>
                                <span class="block text-2xl font-bold tracking-tight text-navy-950 lg:text-[28px]">{{ $stat['value'] }}</span>
                                <span class="mt-0.5 block text-xs leading-snug text-navy-600">{{ $stat['label'] }}</span>
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-7 flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-navy-500">Recognized by:</span>
                    @foreach ($bodies as $body)
                        <span class="rounded-md border border-cream-300 bg-white px-2.5 py-1 text-xs font-bold tracking-wide text-navy-700">{{ $body }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Media + floating proof cards --}}
            <div class="relative">
                <x-site.image-placeholder
                    class="aspect-4/3 w-full rounded-2xl shadow-xl shadow-navy-900/10 lg:aspect-[5/4]"
                    label="Compliance professionals attending a live training session"
                    :seed="0"
                />

                {{-- Hidden below lg: overlapping cards need room the comp only has on desktop. --}}
                <div class="pointer-events-none absolute -start-4 top-6 hidden rounded-xl border border-cream-300 bg-white/95 p-3 shadow-lg backdrop-blur-sm lg:block">
                    <div class="flex items-center gap-x-2.5">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-gold-100 text-lg" aria-hidden="true">📜</span>
                        <div>
                            <p class="text-[13px] font-bold leading-tight text-navy-950">Certificate Ready</p>
                            <p class="text-[11px] text-navy-500">Instant download</p>
                        </div>
                    </div>
                </div>

                <div class="pointer-events-none absolute -end-3 top-1/4 hidden rounded-xl border border-cream-300 bg-white/95 p-3 shadow-lg backdrop-blur-sm lg:block">
                    <div class="flex items-center gap-x-2.5">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-navy-100 text-navy-800" aria-hidden="true">
                            <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.4-3 8.3-7 10-4-1.7-7-5.6-7-10V6l7-3Z" /><path d="m9 12 2 2 4-4" /></svg>
                        </span>
                        <div>
                            <p class="text-[13px] font-bold leading-tight text-navy-950">HRCI Approved</p>
                            <p class="text-[11px] text-navy-500">Industry Accredited</p>
                        </div>
                    </div>
                </div>

                <div class="pointer-events-none absolute -bottom-4 start-6 hidden rounded-xl border border-cream-300 bg-white/95 px-4 py-3 shadow-lg backdrop-blur-sm lg:block">
                    <p class="text-[13px] font-bold leading-tight text-navy-950">Live Webinars Weekly</p>
                    <p class="text-[11px] text-navy-500">Next: Aug 22, 2025</p>
                </div>

                <div class="pointer-events-none absolute -end-4 bottom-8 hidden rounded-xl border border-cream-300 bg-white/95 px-4 py-3 text-center shadow-lg backdrop-blur-sm lg:block">
                    <p class="text-xl font-bold leading-none text-navy-950">4.9</p>
                    <p class="mt-1 text-[11px] tracking-tight text-gold-500" aria-hidden="true">★★★★★</p>
                    <p class="text-[11px] text-navy-500">Rating</p>
                </div>
            </div>
        </div>
    </div>
</section>
