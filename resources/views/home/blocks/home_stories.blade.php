{{-- "Success Stories", driven by its `home_stories` block. --}}
@php
    $stories = collect($block->rows('stories'))
        ->map(fn (array $row): array => [
            'quote' => $row['quote'] ?? '',
            'name' => $row['name'] ?? '',
            'role' => $row['role'] ?? '',
            'initials' => $row['initials'] ?? '',
        ])
        ->all();
@endphp

<x-home.stories
    :eyebrow="$block->value('eyebrow')"
    :heading="$block->value('heading')"
    :rating="$block->value('rating')"
    :reviews="$block->value('reviews')"
    :stories="$stories ?: null"
/>
