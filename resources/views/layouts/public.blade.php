<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('meta_description', 'Live and recorded compliance webinars led by certified industry experts. Earn HRCI, SHRM and NASBA/CPE recertification credits.')">

    {{-- Placeholder Open Graph — CLAUDE.md §5.6 replaces this with the
         polymorphic seo_meta record, editable in Filament, at P6. --}}
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="@yield('title', config('app.name'))">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <link rel="canonical" href="{{ url()->current() }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#top" class="skip-link">Skip to content</a>

    <div class="site-shell" x-data="site">
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
</body>
</html>
