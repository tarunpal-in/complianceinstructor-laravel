@props([
    'label' => null,
    'seed' => 0,
])

@php
    // No photography was supplied with design-ui/Homepage.pdf, so every image
    // slot renders as a deterministic gradient at the comp's exact dimensions.
    // Swap for spatie/medialibrary conversions once the client supplies assets
    // (CLAUDE.md §3) — the surrounding layout does not change.
    $gradients = [
        'from-navy-700 to-navy-950',
        'from-navy-500 to-navy-800',
        'from-gold-700 to-navy-900',
        'from-navy-600 to-navy-900',
        'from-navy-800 to-navy-950',
        'from-gold-800 to-navy-800',
    ];
    $gradient = $gradients[$seed % count($gradients)];
@endphp

<div
    {{ $attributes->class(['relative isolate overflow-hidden bg-gradient-to-br', $gradient]) }}
    role="img"
    aria-label="{{ $label ?? 'Placeholder image' }}"
>
    <div class="absolute inset-0 bg-dot-field text-white/10" aria-hidden="true"></div>
    <div class="absolute inset-0 grid place-items-center" aria-hidden="true">
        <svg class="h-8 w-8 text-white/25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="3" y="4" width="18" height="14" rx="2" />
            <path d="m3 14 4.5-4.5 3.5 3.5 3-3L21 15" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="8.5" cy="8.5" r="1.2" />
        </svg>
    </div>
    {{ $slot }}
</div>
