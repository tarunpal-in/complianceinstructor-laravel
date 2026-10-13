{{--
    The homepage hero, driven by its `home_hero` page-section block.

    This partial is the thin seam between stored block data and the hand-styled
    `components/home/hero` component: it reads the block's fields and hands them
    over as props. Any field left blank falls back to the comp default inside the
    component, so the homepage never renders a blank headline (CLAUDE.md §2).
--}}
@php
    $stats = collect($block->rows('stats'))
        ->map(fn (array $row): array => ['value' => $row['value'] ?? '', 'label' => $row['label'] ?? ''])
        ->all();

    $bodies = collect($block->rows('bodies'))
        ->pluck('label')
        ->filter()
        ->values()
        ->all();
@endphp

<x-home.hero
    :eyebrow="$block->value('eyebrow')"
    :heading-lead="$block->value('heading_lead')"
    :heading-highlight="$block->value('heading_highlight')"
    :heading-tail="$block->value('heading_tail')"
    :lede="$block->value('lede')"
    :primary-label="$block->value('primary_label')"
    :secondary-label="$block->value('secondary_label')"
    :secondary-url="$block->value('secondary_url')"
    :stats="$stats ?: null"
    :bodies="$bodies ?: null"
/>
