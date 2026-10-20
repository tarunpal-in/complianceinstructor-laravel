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

    // The rotating "Certificate Ready" card's lines (title + subtitle each).
    $featureCards = collect($block->rows('feature_cards'))
        ->map(fn (array $row): array => ['title' => $row['title'] ?? '', 'subtitle' => $row['subtitle'] ?? ''])
        ->filter(fn (array $row): bool => $row['title'] !== '')
        ->values()
        ->all();

    // The rotating "Approved" card cycles through every active accrediting body.
    $accreditationCodes = \App\Support\HomepageContent::accreditationCodes();

    // The admin-uploaded hero image(s), stored on the public disk via the
    // block's file field. One shows static; several rotate as a slider. Falls
    // back to the shipped default inside the component when none are set. The
    // legacy single `image` key is still honoured.
    $heroImagePaths = $block->value('images');
    $heroImagePaths = is_array($heroImagePaths) ? $heroImagePaths : array_filter([$block->value('image')]);
    $heroImageUrls = collect($heroImagePaths)
        ->filter()
        ->map(fn ($path): string => \Illuminate\Support\Facades\Storage::disk('public')->url($path))
        ->values()
        ->all();
@endphp

<x-home.hero
    :image-urls="$heroImageUrls ?: null"
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
    :feature-cards="$featureCards ?: null"
    :accreditations="$accreditationCodes ?: null"
    :accreditation-suffix="$block->value('accreditation_suffix')"
    :accreditation-note="$block->value('accreditation_note')"
/>
