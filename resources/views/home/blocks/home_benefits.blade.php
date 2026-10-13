{{-- The benefit strip, driven by its `home_benefits` block. --}}
@php
    $benefits = collect($block->rows('benefits'))
        ->map(fn (array $row): array => [
            'icon' => $row['icon'] ?? '',
            'title' => $row['title'] ?? '',
            'body' => $row['body'] ?? '',
        ])
        ->all();
@endphp

<x-home.benefits :benefits="$benefits ?: null" />
