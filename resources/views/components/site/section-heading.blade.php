@props([
    'eyebrow' => null,
    'title',
    'subtitle' => null,
    'align' => 'center',
])

<div {{ $attributes->class([
    'max-w-3xl',
    'mx-auto text-center' => $align === 'center',
]) }}>
    @if ($eyebrow)
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-gold-600">{{ $eyebrow }}</p>
    @endif

    <h2 class="mt-2.5 text-3xl font-bold tracking-tight text-navy-950 sm:text-4xl lg:text-[40px] lg:leading-[1.15]">
        {{ $title }}
    </h2>

    @if ($subtitle)
        <p @class([
            'mt-3.5 text-base leading-relaxed text-navy-600',
            'mx-auto max-w-2xl' => $align === 'center',
        ])>{{ $subtitle }}</p>
    @endif
</div>
