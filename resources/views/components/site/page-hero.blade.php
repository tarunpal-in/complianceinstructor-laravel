@props(['page'])

{{-- The editable hero for a core page (CorePage). Renders nothing without a
     page, so a controller that could not resolve one degrades to no banner
     rather than an error. An optional background image sits behind a dark tint
     (when the overlay is on) so the hero text stays legible over a photo. --}}
@if ($page)
    @php($image = $page->heroImageUrl())
    <section class="site-hero @if ($image) site-hero--image @endif"
             @if ($image) style="background-image: url('{{ $image }}')" @endif>
        @if ($image && $page->hero_overlay)
            <div class="site-hero-tint" aria-hidden="true"></div>
        @endif

        <div class="site-hero-inner">
            @if (filled($page->hero_eyebrow))
                <span class="site-hero-eyebrow">{{ $page->hero_eyebrow }}</span>
            @endif

            <h1>{{ $page->hero_title }}</h1>

            @if (filled($page->hero_subtitle))
                <p>{{ $page->hero_subtitle }}</p>
            @endif
        </div>
    </section>
@endif
