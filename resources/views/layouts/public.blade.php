<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- SEO head, driven by the per-page seo_meta record with computed
         fallbacks (CLAUDE.md §5.6). A view provides $seo via its controller;
         a page that does not — a one-off static view — gets a sensible
         site-level default here rather than nothing. --}}
    @include('partials.seo-head', ['seo' => $seo ?? \App\Seo\Seo::site()])

    {{-- Structured data for this page — Organization and WebSite on every page,
         plus Article/Event/Product/Breadcrumb where a controller supplies them
         (client SEO brief §11). --}}
    @include('partials.schema', ['schemas' => $schemas ?? []])

    {{-- Analytics, Search Console and chat, when the client has set them up. --}}
    @include('partials.integrations-head')

    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#top" class="skip-link">Skip to content</a>

    <div class="site-shell" x-data="site" x-on:notice.window="showNotice($event.detail)">
        <x-site.promo-bar />
        <x-site.navbar />

        <main id="top">
            @yield('content')
        </main>

        <x-site.footer />

        {{-- Matches the comp's transient confirmation toast. Every action on this
             page is inert until P2/P3 wire real routes. --}}
        <div class="toast" x-show="notice" x-cloak x-text="notice"></div>
    </div>

    {{-- Livewire 3 on every public page so its Alpine is the single Alpine the
         marketing pages and the cart/checkout both use (CLAUDE.md §3). --}}
    @livewireScripts
    @include('partials.integrations-body')
</body>
</html>
