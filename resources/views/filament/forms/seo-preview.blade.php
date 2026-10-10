{{--
    A Google-style result preview for the SEO panel.

    The point is length feedback: a title too long to show is the single most
    common on-page SEO mistake, and a snippet that visibly truncates is worth
    more than a character counter nobody reads. Where a field is blank it says so
    rather than rendering empty, so "leave it blank" reads as a choice with a
    known outcome.
--}}
@php
    $siteName = \App\Support\Business::name();
    $shownTitle = $title ?? 'The page\'s own title — '.$siteName;
    $shownDescription = $description ?? 'The page\'s own summary will appear here.';
@endphp

<div class="max-w-xl rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
    <div class="truncate text-xs text-gray-600 dark:text-gray-400">
        {{ \App\Support\Business::name() }} › …
    </div>
    <div class="truncate text-lg text-[#1a0dab] dark:text-[#8ab4f8] {{ $title === null ? 'italic opacity-70' : '' }}">
        {{ $shownTitle }}
    </div>
    <div class="mt-0.5 line-clamp-2 text-sm text-gray-700 dark:text-gray-300 {{ $description === null ? 'italic opacity-70' : '' }}">
        {{ $shownDescription }}
    </div>
</div>
