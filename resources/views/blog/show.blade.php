@extends('layouts.public')


@section('content')
    <div class="blog-crumbs">
        @include('partials.breadcrumbs')
    </div>
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

    @php
        $authorName = $post->author?->name;
        $authorInitials = $authorName
            ? \Illuminate\Support\Str::of($authorName)->explode(' ')->filter()
                ->map(fn (string $word): string => \Illuminate\Support\Str::substr($word, 0, 1))
                ->take(2)->implode('')
            : null;
    @endphp

    <div class="section blog-layout">
        <div class="blog-main">
            <article class="blog-article">
                <header class="blog-article-header">
                    @if ($post->category)
                        <a class="blog-card-category" href="{{ route('blog.index', ['category' => $post->category->slug]) }}">
                            {{ $post->category->name }}
                        </a>
                    @endif

                    <h1>{{ $post->title }}</h1>

                    <div class="blog-article-meta">
                        @if ($authorName)
                            <span class="blog-byline"><span class="blog-avatar">{{ $authorInitials }}</span> {{ $authorName }}</span>
                            <span class="blog-meta-dot" aria-hidden="true"></span>
                        @endif
                        <span><x-ui.icon name="calendar-days" :size="15" /> {{ $post->published_at?->format('j M Y') }}</span>
                        <span class="blog-meta-dot" aria-hidden="true"></span>
                        <span><x-ui.icon name="clock-3" :size="15" /> {{ $post->describeReadingTime() }}</span>
                    </div>
                </header>

                @if ($cover = $post->coverUrl('banner'))
                    <img class="blog-article-cover" src="{{ $cover }}" alt="">
                @else
                    <x-blog.placeholder :post="$post" class="blog-article-cover" :size="76" />
                @endif

                <x-blog.share :post="$post" />

                {{-- Through the sanitiser, never raw — the column holds whatever was
                     pasted, seeded or written into it (§9). --}}
                <div class="pb-prose blog-article-body">{!! \App\Support\Html::sanitize($post->body) !!}</div>

                {{-- Conversion: every article is a doorway back to the catalogue. --}}
                <div class="blog-inline-cta">
                    <div>
                        <strong>Put this into practice.</strong>
                        <span>Browse compliance webinars and earn CEU credits toward your certification.</span>
                    </div>
                    <a href="{{ route('webinars.index') }}">See webinars <x-ui.icon name="arrow-right" :size="16" /></a>
                </div>

                @if ($post->tags->isNotEmpty())
                    <ul class="blog-tags">
                        @foreach ($post->tags as $tag)
                            <li>{{ $tag->name }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($authorName)
                    <div class="blog-author">
                        <span class="blog-author-avatar">{{ $authorInitials }}</span>
                        <div>
                            <span class="blog-author-label">Written by</span>
                            <strong>{{ $authorName }}</strong>
                        </div>
                    </div>
                @endif
            </article>
        </div>

        <aside class="blog-sidebar">
            <div class="blog-sidebar-inner">
                <div class="blog-cta-card">
                    <span class="blog-cta-icon"><x-ui.icon name="award" :size="26" /></span>
                    <h3>Earn CEU credits</h3>
                    <p>Live and on-demand compliance webinars with HRCI, SHRM, CPE and APA credit.</p>
                    <a href="{{ route('webinars.index') }}" class="blog-cta-btn">Browse webinars <x-ui.icon name="arrow-right" :size="16" /></a>
                </div>

                <div class="blog-side-card">
                    <h3>Stay informed</h3>
                    <p>Regulatory updates and practical guidance in your inbox.</p>
                    <form class="blog-side-form" method="POST" action="{{ route('newsletter.store') }}">
                        @csrf
                        <div class="comment-trap" aria-hidden="true">
                            <label for="blog-company-website">Leave this empty</label>
                            <input id="blog-company-website" type="text"
                                   name="{{ \App\Http\Requests\StoreSubscriberRequest::TRAP_FIELD }}"
                                   tabindex="-1" autocomplete="off">
                        </div>
                        <label for="blog-newsletter-email" class="sr-only">Email address</label>
                        <input id="blog-newsletter-email" type="email" name="email" required
                               autocomplete="email" placeholder="your@email.com">
                        <button type="submit">Subscribe</button>
                    </form>
                </div>
            </div>
        </aside>
    </div>

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
