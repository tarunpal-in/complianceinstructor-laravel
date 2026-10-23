@extends('layouts.public')


@section('content')
    <section class="section blog-index">
        <div class="section-heading centered">
            <div class="eyebrow">INSIGHT</div>
            <h1>{{ $activeCategory?->name ?? 'From the blog' }}</h1>
            <p>{{ $activeCategory?->description ?: 'Regulatory updates and practical guidance between webinars.' }}</p>
        </div>

        @if ($categories->isNotEmpty())
            <nav class="blog-filters" aria-label="Categories">
                <a @class(['blog-filter', 'active' => $activeCategory === null]) href="{{ route('blog.index') }}">All</a>
                @foreach ($categories as $category)
                    <a
                        @class(['blog-filter', 'active' => $activeCategory?->is($category)])
                        href="{{ route('blog.index', ['category' => $category->slug]) }}"
                    >{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        @if ($posts->isEmpty())
            <p class="blog-empty">Nothing published here yet.</p>
        @else
            @php
                // The first post leads as a featured card, but only on the first
                // page — later pages are a plain grid so the hierarchy is not
                // repeated down the pagination. Read off the underlying collection
                // so first()/slice() are available whatever the paginator exposes.
                $items = $posts->getCollection();
                $featured = $posts->currentPage() === 1 ? $items->first() : null;
                $rest = $featured ? $items->slice(1) : $items;
            @endphp

            @if ($featured)
                <x-blog.featured :post="$featured" />
            @endif

            @if ($rest->isNotEmpty())
                <div class="blog-grid">
                    @foreach ($rest as $post)
                        <x-blog.card :post="$post" />
                    @endforeach
                </div>
            @endif

            @if ($posts->hasPages())
                <div class="blog-pagination">{{ $posts->links('pagination.default') }}</div>
            @endif
        @endif
    </section>
@endsection
