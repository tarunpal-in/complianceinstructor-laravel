@extends('layouts.public')

@php($seo = $seo ?? \App\Seo\Seo::site('Page not found', robots: 'noindex, follow'))

{{--
    The custom 404 — client SEO brief §4.

    It keeps a visitor who hit a dead link on the site rather than bouncing: a
    clear message, the full site navigation (from the layout), a search box, a
    way home, and links to the sections most people are looking for. The 404 HTTP
    status keeps it out of the index on its own; the noindex set by the view
    composer is belt and braces.

    Self-contained styles, because this page must render even when something has
    gone wrong, without depending on a built stylesheet being present.
--}}

@section('content')
    <style>
        .nf { max-width: 44rem; margin: 0 auto; padding: 5rem 1.25rem 6rem; text-align: center; }
        .nf-code { font-size: 4.5rem; font-weight: 800; line-height: 1; color: #0c4d80; letter-spacing: -0.03em; }
        .nf-title { margin: 1rem 0 0.5rem; font-size: 1.75rem; font-weight: 700; color: #0b2540; }
        .nf-lead { margin: 0 auto 2rem; max-width: 34rem; color: #4a5a6a; line-height: 1.6; }
        .nf-search { display: flex; gap: 0.5rem; max-width: 28rem; margin: 0 auto 2.25rem; }
        .nf-search input { flex: 1; padding: 0.7rem 0.9rem; border: 1px solid #cdd8e3; border-radius: 0.5rem; font-size: 1rem; }
        .nf-search button { padding: 0.7rem 1.1rem; border: 0; border-radius: 0.5rem; background: #f8ad1b; color: #0b2540; font-weight: 700; cursor: pointer; }
        .nf-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.75rem 1.5rem; }
        .nf-links a { color: #0c4d80; font-weight: 600; text-decoration: none; }
        .nf-links a:hover { text-decoration: underline; }
        .nf-home { display: inline-block; margin-bottom: 2.5rem; padding: 0.75rem 1.5rem; border-radius: 0.5rem; background: #0c4d80; color: #fff; font-weight: 700; text-decoration: none; }
        @media (prefers-color-scheme: dark) {
            .nf-code { color: #7ea6c9; } .nf-title { color: #e8eef4; } .nf-lead { color: #9fb2c4; }
        }
    </style>

    <section class="nf">
        <div class="nf-code">404</div>
        <h1 class="nf-title">We couldn't find that page</h1>
        <p class="nf-lead">
            The page may have moved, or the link that brought you here may be out of date.
            Try a search, head back home, or pick up one of the paths below.
        </p>

        <form class="nf-search" action="{{ route('blog.index') }}" method="get" role="search">
            <input type="search" name="q" placeholder="Search articles…" aria-label="Search">
            <button type="submit">Search</button>
        </form>

        <a class="nf-home" href="{{ route('home') }}">Back to the homepage</a>

        <nav class="nf-links" aria-label="Popular destinations">
            <a href="{{ route('home') }}#webinars">Upcoming webinars</a>
            <a href="{{ route('blog.index') }}">The blog</a>
            <a href="{{ route('membership.index') }}">Membership</a>
            <a href="{{ route('contact') }}">Contact us</a>
        </nav>
    </section>
@endsection
