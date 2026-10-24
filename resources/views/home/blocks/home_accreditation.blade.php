{{-- "Accreditation Partners", driven by its `home_accreditation` block. --}}
@php
    $rows = collect($block->rows('partners'));

    // Logos live on the Accreditation records (an admin picks each one in
    // Admin → Accreditations), not in this block. The block only curates which
    // bodies show, in what order, with what badge — so the logo is pulled in by
    // matching the row's code. Without this, a logo assigned in admin never
    // reaches the homepage, because the block supplies the partner list.
    $logos = \App\Models\Accreditation::query()
        ->whereIn('code', $rows->pluck('code')->filter()->map(fn ($c): string => (string) $c)->all())
        ->with('logoMedia')
        ->get()
        ->mapWithKeys(fn (\App\Models\Accreditation $a): array => [strtoupper($a->code) => $a->logoUrl()]);

    $partners = $rows
        ->map(fn (array $row): array => [
            'code' => $row['code'] ?? '',
            'badge' => $row['badge'] ?? '',
            'name' => $row['name'] ?? '',
            'logo_url' => $logos[strtoupper((string) ($row['code'] ?? ''))] ?? null,
        ])
        ->all();
@endphp

<x-home.accreditation
    :eyebrow="$block->value('eyebrow')"
    :heading="$block->value('heading')"
    :intro="$block->value('intro')"
    :partners="$partners ?: null"
/>
