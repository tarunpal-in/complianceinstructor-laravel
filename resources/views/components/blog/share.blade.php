@props(['post'])

@php
    $url = url($post->path());
    $title = $post->title;
@endphp

{{-- Share row. Copy-link is Alpine-only (no network), the others open the
     network's own share dialog in a new tab. --}}
<div class="blog-share" x-data="{ copied: false }">
    <span class="blog-share-label">Share</span>

    <a class="blog-share-btn"
       href="https://www.linkedin.com/sharing/share-offsite/?url={{ urlencode($url) }}"
       target="_blank" rel="noopener" aria-label="Share on LinkedIn">
        <x-ui.icon name="linkedin" :size="17" />
    </a>

    <a class="blog-share-btn"
       href="https://twitter.com/intent/tweet?url={{ urlencode($url) }}&text={{ urlencode($title) }}"
       target="_blank" rel="noopener" aria-label="Share on X">
        <x-ui.icon name="x" :size="17" />
    </a>

    <button type="button" class="blog-share-btn"
            x-on:click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 1800)"
            :aria-label="copied ? 'Link copied' : 'Copy link'">
        <span x-show="!copied"><x-ui.icon name="share-2" :size="17" /></span>
        <span x-show="copied" x-cloak><x-ui.icon name="check" :size="17" /></span>
    </button>
</div>
