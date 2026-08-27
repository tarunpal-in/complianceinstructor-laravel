@props(['plans' => []])

<section class="bg-cream-100 py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <x-site.section-heading
            eyebrow="Flexible Pricing"
            title="Membership Plans"
            subtitle="Invest in your professional development with a plan that matches your learning pace."
        />

        <ul class="mt-12 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($plans as $plan)
                <li @class([
                    'relative flex flex-col rounded-2xl bg-white p-6 transition',
                    'border-2 border-gold-500 shadow-xl shadow-gold-500/10 xl:-mt-4 xl:mb-4' => $plan['featured'],
                    'border border-cream-300 hover:border-navy-200 hover:shadow-md' => ! $plan['featured'],
                ])>
                    @if ($plan['featured'])
                        <span class="absolute -top-3 start-1/2 -translate-x-1/2 rounded-full bg-gold-500 px-3.5 py-1 text-[10px] font-bold uppercase tracking-wider text-navy-950 shadow-sm">
                            Most Popular
                        </span>
                    @endif

                    <p class="text-xs font-bold uppercase tracking-wider text-gold-700">{{ $plan['name'] }}</p>

                    <p class="mt-3 flex items-baseline gap-x-1.5">
                        <span class="text-4xl font-bold tracking-tight text-navy-950">${{ number_format($plan['price_cents'] / 100) }}</span>
                        <span class="text-sm text-navy-500">/ {{ $plan['term'] }}</span>
                    </p>

                    <p class="mt-2 text-sm font-semibold text-navy-800">{{ $plan['allowance'] }}</p>

                    <ul class="mt-6 flex-1 space-y-3 border-t border-cream-200 pt-6">
                        @foreach ($plan['features'] as $feature)
                            <li @class([
                                'flex items-start gap-x-2.5 text-[13px] leading-snug',
                                'text-navy-700' => $feature['included'],
                                'text-navy-300 line-through' => ! $feature['included'],
                            ])>
                                @if ($feature['included'])
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-green-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
                                    <span class="sr-only">Included:</span>
                                @else
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-navy-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.3 4.3a1 1 0 0 1 1.4 0L10 8.6l4.3-4.3a1 1 0 1 1 1.4 1.4L11.4 10l4.3 4.3a1 1 0 0 1-1.4 1.4L10 11.4l-4.3 4.3a1 1 0 0 1-1.4-1.4L8.6 10 4.3 5.7a1 1 0 0 1 0-1.4Z" clip-rule="evenodd" /></svg>
                                    <span class="sr-only">Not included:</span>
                                @endif
                                {{ $feature['label'] }}
                            </li>
                        @endforeach
                    </ul>

                    <a href="#" @class([
                        'mt-6 block rounded-full py-3 text-center text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2',
                        'bg-gold-500 text-navy-950 hover:bg-gold-400 focus-visible:outline-gold-600' => $plan['featured'],
                        'bg-navy-900 text-white hover:bg-navy-800 focus-visible:outline-navy-600' => ! $plan['featured'],
                    ])>
                        Get Started
                    </a>
                </li>
            @endforeach
        </ul>

        {{-- Callback strip --}}
        <div class="mt-12 flex flex-col items-center gap-5 rounded-2xl border border-cream-300 bg-white px-6 py-7 text-center sm:flex-row sm:justify-between sm:text-start lg:px-9">
            <div>
                <h3 class="text-lg font-bold tracking-tight text-navy-950">Not sure which plan is right for you?</h3>
                <p class="mt-1 text-sm text-navy-600">Our team can help match you with the best training package for your goals and budget.</p>
            </div>

            <a href="#" class="inline-flex shrink-0 items-center gap-x-2 rounded-full border border-navy-300 bg-white px-6 py-3 text-sm font-semibold text-navy-900 transition hover:border-navy-400 hover:bg-navy-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-600">
                Request a Callback
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.5 2.8.6a2 2 0 0 1 1.7 2Z" /></svg>
            </a>
        </div>
    </div>
</section>
