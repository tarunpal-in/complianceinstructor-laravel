{{--
    The SEO head block — the one place "what search engines see" is rendered.

    Everything here comes from a resolved App\Seo\SeoData object; this partial
    makes no decisions. The fallback chain, the trailing-slash canonical and the
    length trimming all happened in App\Seo\Seo before the view was reached, so a
    bug in "what title shows" is found in one testable class rather than chased
    across templates.

    There is deliberately no meta-keywords tag. The brief forbids it, the
    SeoData object has no field for it, and SeoTest fails the build if one ever
    appears — including, as it happens, in a comment like this one.
--}}
@php /** @var \App\Seo\SeoData $seo */ @endphp
<title>{{ $seo->title }}</title>
<meta name="description" content="{{ $seo->description }}">
<meta name="robots" content="{{ $seo->robots }}">

<link rel="canonical" href="{{ $seo->canonical }}">

<meta property="og:site_name" content="{{ \App\Support\Business::name() }}">
<meta property="og:title" content="{{ $seo->title }}">
<meta property="og:description" content="{{ $seo->description }}">
<meta property="og:type" content="{{ $seo->ogType }}">
{{-- og:url is the canonical, so a shared link resolves to the preferred URL. --}}
<meta property="og:url" content="{{ $seo->canonical }}">
@if ($seo->ogImage)
    <meta property="og:image" content="{{ $seo->ogImage }}">
@endif

<meta name="twitter:card" content="{{ $seo->ogImage ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seo->title }}">
<meta name="twitter:description" content="{{ $seo->description }}">
@if ($seo->ogImage)
    <meta name="twitter:image" content="{{ $seo->ogImage }}">
@endif
