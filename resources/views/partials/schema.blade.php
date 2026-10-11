{{--
    JSON-LD structured data — client SEO brief §11.

    Organization and WebSite describe the site and go on every page; $schemas
    carries whatever the current page's controller added (an Article, an Event,
    a breadcrumb trail). Each is its own <script>, which is what Google
    recommends and what keeps one malformed block from invalidating the rest.

    JSON_UNESCAPED_SLASHES so URLs read cleanly; JSON_HEX_TAG so a stray "</script>"
    inside a value — an admin-authored title, say — cannot break out of the tag.
--}}
@php
    $all = array_merge([
        \App\Seo\Schema::organization(),
        \App\Seo\Schema::website(),
    ], $schemas ?? []);
@endphp

@foreach ($all as $schema)
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endforeach
