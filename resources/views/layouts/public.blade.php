<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-pt-24">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('meta_description', 'Live and recorded compliance webinars led by certified industry experts. Earn HRCI, SHRM and NASBA/CPE recertification credits.')">

    {{-- Placeholder Open Graph. CLAUDE.md §5.6 replaces this with the
         polymorphic seo_meta record in P6, driven from the admin. --}}
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="@yield('title', config('app.name'))">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">

    <link rel="canonical" href="{{ url()->current() }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white font-sans text-navy-900 antialiased">
    {{-- WCAG AA (§7 P9): keyboard users get past the 9-item nav in one tab. --}}
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-100 focus:rounded-lg focus:bg-navy-900 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">
        Skip to content
    </a>

    <x-site.announcement-bar />
    <x-site.header />

    <main id="main">
        @yield('content')
    </main>

    <x-site.footer />
</body>
</html>
