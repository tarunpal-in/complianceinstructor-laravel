@props([
    'categories' => null,
    'eyebrow' => null,
    'heading' => null,
    'intro' => null,
    'layout' => null,
    'autoplay' => false,
])

@php
    $eyebrow = $eyebrow ?: 'BROWSE BY INDUSTRY';
    $heading = $heading ?: 'Webinar Categories';
    $intro = $intro ?: 'Built around the regulations you actually answer to';
    $categories = $categories ?: [
        ['name' => 'Manufacturing', 'count' => 48, 'image' => 'laptop'],
        ['name' => 'Human Resource', 'count' => 48, 'image' => 'office'],
        ['name' => 'Microsoft Office', 'count' => 48, 'image' => 'seminar'],
    ];

    // The admin picks the display style; anything unexpected falls back to grid.
    $layout = in_array($layout, ['grid', 'slider'], true) ? $layout : 'grid';
@endphp

<section class="categories section" id="industry">
    <div class="section-heading centered">
        <div class="eyebrow">{{ $eyebrow }}</div>
        <h2>{{ $heading }}</h2>
        <p>{{ $intro }}</p>
    </div>

    @if ($layout === 'slider')
        {{-- Horizontal slider: scroll-snap track with prev/next controls, so the
             admin can list more categories than fit in one row. Pauses on hover
             when auto-advance is on. --}}
        <div class="category-slider"
             x-data="categorySlider({ autoplay: {{ $autoplay ? 'true' : 'false' }} })"
             @mouseenter="pause()" @mouseleave="resume()">
            <button type="button" class="cat-slide-btn prev" @click="prev()" aria-label="Previous categories">‹</button>

            {{-- data-lenis-prevent: this is a horizontal scroll-snap track, so
                 Lenis must not hijack wheel/touch inside it. --}}
            <div class="category-track" x-ref="track" tabindex="0" role="group" aria-label="Webinar categories" data-lenis-prevent>
                @foreach ($categories as $category)
                    @include('home.partials.category-card', ['category' => $category])
                @endforeach
            </div>

            <button type="button" class="cat-slide-btn next" @click="next()" aria-label="Next categories">›</button>
        </div>
    @else
        {{-- One row on desktop: the grid takes as many columns as there are
             categories (capped at 4 so the cards never get too narrow), rather
             than a fixed three that wraps a fourth onto its own row. --}}
        <div class="category-grid" style="--cat-cols: {{ min(max(count($categories), 1), 4) }}">
            @foreach ($categories as $category)
                @include('home.partials.category-card', ['category' => $category])
            @endforeach
        </div>
    @endif
</section>
