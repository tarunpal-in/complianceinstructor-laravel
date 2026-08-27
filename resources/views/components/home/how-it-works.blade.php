@props(['steps' => []])

<section class="bg-white py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <x-site.section-heading
            eyebrow="Simple Process"
            title="How It Works"
            subtitle="From registration to certification in three straightforward steps."
        />

        <ol class="mt-12 grid gap-6 md:grid-cols-3 lg:gap-5">
            @foreach ($steps as $i => $step)
                <li class="relative">
                    <div class="h-full rounded-2xl border border-cream-300 bg-cream-50 p-6 transition hover:border-gold-300 hover:shadow-md lg:p-7">
                        <div class="flex items-start justify-between">
                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-gold-100 text-gold-700" aria-hidden="true">
                                {!! $step['icon'] !!}
                            </span>
                            <span class="text-2xl font-bold tracking-tight text-navy-100" aria-hidden="true">{{ $step['number'] }}</span>
                        </div>

                        <h3 class="mt-5 text-lg font-bold tracking-tight text-navy-950">
                            <span class="sr-only">Step {{ $i + 1 }}:</span>{{ $step['title'] }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-navy-600">{{ $step['body'] }}</p>
                    </div>

                    {{-- Connector arrow, desktop only — decorative. --}}
                    @if (! $loop->last)
                        <span class="absolute -end-3.5 top-1/2 hidden -translate-y-1/2 text-navy-200 md:block" aria-hidden="true">
                            <svg class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.6l-3.1-3.1a.75.75 0 1 1 1.06-1.06l4.38 4.38a.75.75 0 0 1 0 1.06l-4.38 4.38a.75.75 0 1 1-1.06-1.06l3.1-3.1H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd" /></svg>
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
</section>
