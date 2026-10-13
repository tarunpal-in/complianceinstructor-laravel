{{-- "Accreditation Partners", driven by its `home_accreditation` block. --}}
@php
    $partners = collect($block->rows('partners'))
        ->map(fn (array $row): array => [
            'code' => $row['code'] ?? '',
            'badge' => $row['badge'] ?? '',
            'name' => $row['name'] ?? '',
        ])
        ->all();
@endphp

<x-home.accreditation
    :eyebrow="$block->value('eyebrow')"
    :heading="$block->value('heading')"
    :intro="$block->value('intro')"
    :partners="$partners ?: null"
/>
