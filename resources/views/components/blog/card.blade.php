@props(['post'])

{{-- One article on the index. --}}
<article class="blog-card">
    <a href="{{ $post->path() }}" class="blog-card-link">
        <div class="blog-card-media">
            @if ($cover = $post->coverUrl('card'))
                <img class="blog-card-image" src="{{ $cover }}" alt="" loading="lazy">
            @else
                <x-blog.placeholder :post="$post" class="blog-card-image" :size="48" />
            @endif

            @if ($post->category)
                <span class="blog-card-category">{{ $post->category->name }}</span>
            @endif
        </div>

        <div class="blog-card-body">
            <h2>{{ $post->title }}</h2>
            <p>{{ $post->summary(120) }}</p>

            <div class="blog-card-foot">
                <div class="blog-card-meta">
                    <span><x-ui.icon name="calendar-days" :size="14" /> {{ $post->published_at?->format('j M Y') }}</span>
                    <span><x-ui.icon name="clock-3" :size="14" /> {{ $post->describeReadingTime() }}</span>
                </div>
                <span class="blog-card-more">Read <x-ui.icon name="arrow-right" :size="15" /></span>
            </div>
        </div>
    </a>
</article>
