@props(['testimonials' => [], 'rating' => '4.8', 'reviews' => 312])

<section class="bg-white py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <x-site.section-heading
                align="start"
                eyebrow="What Professionals Say"
                title="Success Stories"
            />

            <p class="flex shrink-0 items-center gap-x-2 text-sm text-navy-600">
                <span class="text-gold-500" aria-hidden="true">★★★★★</span>
                <span><span class="font-bold text-navy-950">{{ $rating }}</span> from {{ number_format($reviews) }} reviews</span>
            </p>
        </div>

        <ul class="mt-10 grid gap-5 lg:grid-cols-3">
            @foreach ($testimonials as $i => $testimonial)
                <li class="rounded-2xl border border-cream-300 bg-cream-50 transition hover:border-gold-300 hover:shadow-md">
                    <figure class="flex h-full flex-col p-6">
                        <p class="text-gold-500" aria-label="Rated 5 out of 5">
                            <span aria-hidden="true">★★★★★</span>
                        </p>

                        <blockquote class="mt-4 flex-1">
                            <p class="text-sm leading-relaxed text-navy-700">“{{ $testimonial['quote'] }}”</p>
                        </blockquote>

                        <figcaption class="mt-5 flex items-center gap-x-3 border-t border-cream-300 pt-5">
                            <x-site.image-placeholder class="h-10 w-10 shrink-0 rounded-full" :label="$testimonial['name']" :seed="$i + 4" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-navy-950">{{ $testimonial['name'] }}</p>
                                <p class="truncate text-xs text-navy-500">{{ $testimonial['role'] }}</p>
                            </div>
                        </figcaption>
                    </figure>
                </li>
            @endforeach
        </ul>
    </div>
</section>
