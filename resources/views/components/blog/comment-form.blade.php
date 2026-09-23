@props(['post', 'parent' => null])

{{--
    The comment box.

    Rendered once at the foot of the thread and once inside each reply
    disclosure, so `$parent` decides which one this is. Laravel shares one error
    bag across the page, which would otherwise paint the same validation message
    under every form on the article — `$mine` is what keeps an error and the old
    input attached to the box the reader actually typed into.
--}}
@php
    $parentId = $parent?->getKey();
    $mine = (int) old('parent_id') === (int) $parentId;
    $trap = \App\Http\Requests\StoreCommentRequest::TRAP_FIELD;
@endphp

<form class="comment-form" method="POST" action="{{ route('blog.comments.store', ['slug' => $post->slug]) }}">
    @csrf

    @if ($parentId)
        <input type="hidden" name="parent_id" value="{{ $parentId }}">
    @endif

    {{-- The honeypot. Hidden from people, filled in by scripts walking the DOM.
         `tabindex` and `aria-hidden` keep it away from keyboards and screen
         readers too, which a plain `display: none` would not do on its own. --}}
    <div class="comment-trap" aria-hidden="true">
        <label for="{{ $trap }}-{{ $parentId ?? 'root' }}">Leave this empty</label>
        <input id="{{ $trap }}-{{ $parentId ?? 'root' }}" type="text" name="{{ $trap }}" tabindex="-1" autocomplete="off">
    </div>

    <div class="comment-form-fields">
        <label class="comment-field">
            <span>Name</span>
            <input type="text" name="author_name" required maxlength="80"
                   value="{{ $mine ? old('author_name') : '' }}">
        </label>

        <label class="comment-field">
            <span>Email <small>— not published</small></span>
            <input type="email" name="author_email" required maxlength="255"
                   value="{{ $mine ? old('author_email') : '' }}">
        </label>
    </div>

    <label class="comment-field">
        <span>{{ $parent ? 'Your reply to '.$parent->author_name : 'Comment' }}</span>
        <textarea name="body" rows="{{ $parent ? 3 : 5 }}" required maxlength="2000">{{ $mine ? old('body') : '' }}</textarea>
    </label>

    @if ($mine && $errors->any())
        <ul class="comment-errors" role="alert">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    <button type="submit" class="comment-submit">{{ $parent ? 'Post reply' : 'Post comment' }}</button>
</form>
