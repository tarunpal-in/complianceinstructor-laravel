{{-- "How It Works", driven by its `home_process` block. --}}
@php
    $steps = collect($block->rows('steps'))
        ->map(fn (array $row): array => [
            'icon' => $row['icon'] ?? '',
            'title' => $row['title'] ?? '',
            'body' => $row['body'] ?? '',
        ])
        ->all();
@endphp

<x-home.process
    :eyebrow="$block->value('eyebrow')"
    :heading="$block->value('heading')"
    :intro="$block->value('intro')"
    :steps="$steps ?: null"
/>
