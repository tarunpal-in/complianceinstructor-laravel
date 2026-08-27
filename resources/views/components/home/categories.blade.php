@props(['categories' => []])

<section class="bg-white py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <x-site.section-heading
            eyebrow="Browse by Industry"
            title="Webinar Categories"
            subtitle="Built around the regulations you actually answer to."
        />

        <ul class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($categories as $i => $category)
                <li class="group relative overflow-hidden rounded-2xl">
                    <x-site.image-placeholder
                        class="aspect-3/2 w-full transition-transform duration-500 group-hover:scale-105"
                        :label="$category['name']"
                        :seed="$i + 2"
                    />

                    {{-- Gradient scrim: the comp sets white text over photography,
                         which only reaches WCAG AA contrast with a dark overlay. --}}
                    <div class="absolute inset-0 bg-gradient-to-t from-navy-950/90 via-navy-950/40 to-transparent" aria-hidden="true"></div>

                    <div class="absolute inset-x-0 bottom-0 p-5">
                        <p class="inline-block rounded-full bg-white/20 px-2.5 py-1 text-[11px] font-semibold text-white backdrop-blur-sm">
                            {{ $category['count'] }} Courses
                        </p>
                        <h3 class="mt-2 text-xl font-bold tracking-tight text-white">
                            <a href="#" class="before:absolute before:inset-0">{{ $category['name'] }}</a>
                        </h3>
                        <span class="mt-3 inline-flex items-center gap-x-1.5 rounded-full bg-gold-500 px-4 py-2 text-xs font-semibold text-navy-950 transition group-hover:bg-gold-400">
                            View Courses
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.6l-3.1-3.1a.75.75 0 1 1 1.06-1.06l4.38 4.38a.75.75 0 0 1 0 1.06l-4.38 4.38a.75.75 0 1 1-1.06-1.06l3.1-3.1H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd" /></svg>
                        </span>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>
