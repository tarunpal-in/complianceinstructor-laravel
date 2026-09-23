@extends('layouts.public')

@section('title', $post->title.' — '.config('app.name'))
@section('meta_description', $post->summary(155))

@section('content')
    @if ($preview ?? false)
        {{-- Loud on purpose — see pages/show.blade.php for the reasoning. --}}
        <div class="page-preview-banner" role="status">
            <strong>Preview</strong>
            <span>
                @if ($post->derivedStatus() === \App\Enums\PublishStatus::Scheduled)
                    {{ 'This article is scheduled and goes live on '.$post->published_at?->format('j M Y').'.' }}
                @else
                    {{ 'This article has not been published.' }}
                @endif
                {{ 'This link stops working after '.\App\Models\Post::PREVIEW_LIFETIME_DAYS.' days.' }}
            </span>
        </div>
    @endif

    <article class="section blog-article">
        <header class="blog-article-header">
            @if ($post->category)
                <a class="blog-card-category" href="{{ route('blog.index', ['category' => $post->category->slug]) }}">
                    {{ $post->category->name }}
                </a>
            @endif

            <h1>{{ $post->title }}</h1>

            <div class="blog-card-meta">
                @if ($post->author)
                    <span>{{ $post->author->name }}</span>
                    <span>·</span>
                @endif
                <span>{{ $post->published_at?->format('j M Y') }}</span>
                <span>·</span>
                <span>{{ $post->describeReadingTime() }}</span>
            </div>
        </header>

        @if ($cover = $post->coverUrl('banner'))
            <img class="blog-article-cover" src="{{ $cover }}" alt="">
        @endif

        {{-- The editor's own markup, which is why it is not escaped. The
             toolbar is a known, short set of tags — see PostForm. --}}
        <div class="pb-prose blog-article-body">{!! $post->body !!}</div>

        @if ($post->tags->isNotEmpty())
            <ul class="blog-tags">
                @foreach ($post->tags as $tag)
                    <li>{{ $tag->name }}</li>
                @endforeach
            </ul>
        @endif
    </article>

    {{-- Not shown on a preview: the article has no address yet, so there is
         nothing for a comment to be attached to. --}}
    @unless ($preview ?? false)
        <x-blog.comments :post="$post" />
    @endunless

    @if ($related->isNotEmpty())
        <section class="section blog-related">
            <div class="section-heading centered"><h2>More like this</h2></div>

            <div class="blog-grid">
                @foreach ($related as $other)
                    <x-blog.card :post="$other" />
                @endforeach
            </div>
        </section>
    @endif
@endsection
