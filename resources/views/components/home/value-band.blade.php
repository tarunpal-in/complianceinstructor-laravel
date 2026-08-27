@props(['values' => []])

<section class="bg-navy-900 py-12 lg:py-14">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <ul class="grid gap-8 md:grid-cols-3 lg:gap-10">
            @foreach ($values as $value)
                <li class="flex gap-x-4">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white/10 text-gold-400" aria-hidden="true">
                        {!! $value['icon'] !!}
                    </span>
                    <div>
                        <h2 class="text-base font-bold tracking-tight text-white">{{ $value['title'] }}</h2>
                        <p class="mt-1.5 text-sm leading-relaxed text-navy-200">{{ $value['body'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>
