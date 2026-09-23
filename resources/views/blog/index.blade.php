@extends('layouts.public')

@section('title', ($activeCategory?->name ?? 'Blog').' — '.config('app.name'))
@section('meta_description', $activeCategory?->description ?: 'Compliance insight, regulatory updates and practical guidance for HR, payroll and safety professionals.')

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
            <div class="blog-grid">
                @foreach ($posts as $post)
                    <x-blog.card :post="$post" />
                @endforeach
            </div>

            <div class="blog-pagination">{{ $posts->links() }}</div>
        @endif
    </section>
@endsection
