@props(['partners' => []])

<section class="bg-cream-100 py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        {{-- The comp reads "Every CourseMinistry webinar…" — the predecessor's
             name. Bound to config('app.name') so it cannot ship wrong. --}}
        <x-site.section-heading
            eyebrow="Trusted Credentials"
            title="Accreditation Partners"
            :subtitle="'Every '.config('app.name').' webinar carries credits recognized by the industry\'s leading professional certification bodies.'"
        />

        <ul class="mx-auto mt-12 grid max-w-4xl gap-5 sm:grid-cols-3">
            @foreach ($partners as $partner)
                <li class="flex flex-col items-center rounded-2xl border border-cream-300 bg-white p-6 text-center transition hover:border-gold-300 hover:shadow-md">
                    {{-- Wordmark stand-in until the bodies' official logos are
                         supplied; each carries usage rules we must follow. --}}
                    <span class="grid h-16 w-full max-w-32 place-items-center rounded-xl bg-navy-50 text-xl font-black tracking-tight text-navy-800" aria-hidden="true">
                        {{ $partner['code'] }}
                    </span>
                    <h3 class="mt-4 text-sm font-bold tracking-tight text-navy-950">{{ $partner['code'] }}</h3>
                    <p class="mt-1 text-xs leading-snug text-navy-600">{{ $partner['name'] }}</p>
                </li>
            @endforeach
        </ul>
    </div>
</section>
