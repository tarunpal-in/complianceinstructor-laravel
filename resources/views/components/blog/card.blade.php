@props(['post'])

{{-- One article on the index. --}}
<article class="blog-card">
    <a href="{{ $post->path() }}" class="blog-card-link">
        @if ($cover = $post->coverUrl('card'))
            <img class="blog-card-image" src="{{ $cover }}" alt="" loading="lazy">
        @else
            <div class="blog-card-image placeholder" aria-hidden="true"></div>
        @endif

        <div class="blog-card-body">
            @if ($post->category)
                <span class="blog-card-category">{{ $post->category->name }}</span>
            @endif

            <h2>{{ $post->title }}</h2>
            <p>{{ $post->summary(120) }}</p>

            <div class="blog-card-meta">
                <span>{{ $post->published_at?->format('j M Y') }}</span>
                <span>·</span>
                <span>{{ $post->describeReadingTime() }}</span>
            </div>
        </div>
    </a>
</article>
