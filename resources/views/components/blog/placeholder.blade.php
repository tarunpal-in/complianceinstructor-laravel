@props(['post', 'size' => 56])

{{-- A designed stand-in for a missing cover image: a category-toned gradient
     with the topic's icon, so an article with no artwork still reads as
     intentional rather than broken. --}}
@php
    $name = \Illuminate\Support\Str::lower($post->category?->name ?? '');

    // HR is checked before compliance so "HR compliance" reads as HR (green)
    // rather than colliding with "Regulatory updates" on the same blue tone.
    [$tone, $icon] = match (true) {
        str_contains($name, 'hr'), str_contains($name, 'human'), str_contains($name, 'people')
            => ['blog-ph--green', 'users-round'],
        str_contains($name, 'payroll'), str_contains($name, 'tax'), str_contains($name, 'wage'), str_contains($name, 'pay')
            => ['blog-ph--amber', 'monitor'],
        str_contains($name, 'regulat'), str_contains($name, 'law'), str_contains($name, 'compliance'), str_contains($name, 'ethic')
            => ['blog-ph--blue', 'shield-check'],
        default => ['blog-ph--slate', 'file-text'],
    };
@endphp

<div {{ $attributes->merge(['class' => "blog-ph {$tone}"]) }} aria-hidden="true">
    <x-ui.icon :name="$icon" :size="$size" />
</div>
