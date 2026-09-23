@props(['post'])

{{--
    The thread under an article.

    Only approved comments are here: `publishedComments` carries that rule and
    `replies` carries it again for the second level, so neither this view nor
    the next one that renders a comment has to remember it — §14 #9's shape,
    applied to something being read rather than something being bought.
--}}
@php
    $comments = $post->publishedComments;
    $count = $comments->count() + $comments->sum(fn ($comment) => $comment->replies->count());
@endphp

<section class="section blog-comments" id="comments">
    <div class="blog-comments-inner">
        <h2 class="blog-comments-heading">
            {{ $count === 0 ? 'Comments' : trans_choice(':count comment|:count comments', $count, ['count' => $count]) }}
        </h2>

        @if (session('comment_status'))
            <p class="comment-notice" role="status">{{ session('comment_status') }}</p>
        @endif

        @if ($comments->isEmpty())
            <p class="comment-empty">Nothing here yet.</p>
        @endif

        <ol class="comment-list">
            @foreach ($comments as $comment)
                <li class="comment" id="comment-{{ $comment->getKey() }}">
                    <div class="comment-head">
                        <strong>{{ $comment->author_name }}</strong>
                        @if ($comment->isFromStaff())
                            <span class="comment-badge">{{ config('app.name') }}</span>
                        @endif
                        <time datetime="{{ $comment->created_at?->toIso8601String() }}">
                            {{ $comment->created_at?->format('j M Y') }}
                        </time>
                    </div>

                    {{-- Escaped first, then the line breaks put back. A reader
                         typing paragraphs should see paragraphs, and nothing
                         they type should ever be markup. --}}
                    <div class="comment-body">{!! nl2br(e($comment->body)) !!}</div>

                    @if ($post->acceptsComments())
                        {{-- A disclosure rather than a script: the reply box is
                             in the page, closed, and opening it needs nothing
                             loaded. It reopens itself when a reply came back
                             with an error. --}}
                        <details class="comment-reply" @if ((int) old('parent_id') === $comment->getKey()) open @endif>
                            <summary>Reply</summary>
                            <x-blog.comment-form :post="$post" :parent="$comment" />
                        </details>
                    @endif

                    @if ($comment->replies->isNotEmpty())
                        <ol class="comment-replies">
                            @foreach ($comment->replies as $reply)
                                <li class="comment" id="comment-{{ $reply->getKey() }}">
                                    <div class="comment-head">
                                        <strong>{{ $reply->author_name }}</strong>
                                        @if ($reply->isFromStaff())
                                            <span class="comment-badge">{{ config('app.name') }}</span>
                                        @endif
                                        <time datetime="{{ $reply->created_at?->toIso8601String() }}">
                                            {{ $reply->created_at?->format('j M Y') }}
                                        </time>
                                    </div>

                                    <div class="comment-body">{!! nl2br(e($reply->body)) !!}</div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </li>
            @endforeach
        </ol>

        @if ($post->acceptsComments())
            <div class="comment-compose">
                <h3>Leave a comment</h3>
                <p class="comment-note">Comments are read before they appear.</p>

                <x-blog.comment-form :post="$post" />
            </div>
        @else
            <p class="comment-empty">Comments are closed on this article.</p>
        @endif
    </div>
</section>
