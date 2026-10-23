@props(['post'])

{{-- The lead article on the index: a wide card with the artwork beside a
     larger title, to give the page a focal point. --}}
<article class="blog-featured">
    <a href="{{ $post->path() }}" class="blog-featured-link">
        <div class="blog-featured-media">
            @if ($cover = $post->coverUrl('card'))
                <img class="blog-featured-image" src="{{ $cover }}" alt="" loading="lazy">
            @else
                <x-blog.placeholder :post="$post" class="blog-featured-image" :size="88" />
            @endif
        </div>

        <div class="blog-featured-body">
            <div class="blog-featured-tags">
                <span class="blog-featured-flag">Featured</span>
                @if ($post->category)
                    <span class="blog-card-category">{{ $post->category->name }}</span>
                @endif
            </div>

            <h2>{{ $post->title }}</h2>
            <p>{{ $post->summary(220) }}</p>

            <div class="blog-card-foot">
                <div class="blog-card-meta">
                    <span><x-ui.icon name="calendar-days" :size="15" /> {{ $post->published_at?->format('j M Y') }}</span>
                    <span><x-ui.icon name="clock-3" :size="15" /> {{ $post->describeReadingTime() }}</span>
                </div>
                <span class="blog-card-more">Read article <x-ui.icon name="arrow-right" :size="16" /></span>
            </div>
        </div>
    </a>
</article>
