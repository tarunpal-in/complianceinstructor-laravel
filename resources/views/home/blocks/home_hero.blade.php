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

    // The hero image(s): now rows picked from the Media library (each {media_id}).
    // One shows static; several rotate as a slider. Falls back to the shipped
    // default inside the component when none are set. Legacy values are still
    // honoured: a repeater row that is a plain stored path, or the old single
    // `image` key.
    $heroRows = $block->value('images');
    $heroRows = is_array($heroRows) ? $heroRows : array_filter([$block->value('image')]);
    $heroImageUrls = collect($heroRows)
        ->map(function ($row): ?string {
            // New: a repeater row holding a library media id.
            if (is_array($row)) {
                $id = $row['media_id'] ?? null;

                return $id ? optional(\App\Models\Media::find($id))->detailImageUrl() : null;
            }

            // Legacy: a path stored on the public disk by the old upload field.
            return filled($row) ? \Illuminate\Support\Facades\Storage::disk('public')->url($row) : null;
        })
        ->filter()
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
    :primary-url="$block->value('primary_url')"
    :secondary-label="$block->value('secondary_label')"
    :secondary-url="$block->value('secondary_url')"
    :stats="$stats ?: null"
    :bodies="$bodies ?: null"
    :feature-cards="$featureCards ?: null"
    :accreditations="$accreditationCodes ?: null"
    :accreditation-suffix="$block->value('accreditation_suffix')"
    :accreditation-note="$block->value('accreditation_note')"
/>
