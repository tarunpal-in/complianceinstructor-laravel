@props(['partner'])

{{-- One accrediting body: its uploaded logo when it has one, else its code set
     in the brand type so a body with no logo still reads. Shared by the
     scrolling marquee and the static list, so they never drift apart. --}}
<div class="credential-card">
    @if (! empty($partner['logo_url']))
        <img class="credential-logo" src="{{ $partner['logo_url'] }}"
             alt="{{ $partner['name'] ?? ($partner['code'] ?? '') }}" loading="lazy">
    @else
        <strong>{{ $partner['code'] ?? '' }}</strong>
    @endif
    {{-- Escaped first, then nl2br: the badge is client copy, never trusted HTML. --}}
    <small>{!! nl2br(e($partner['badge'] ?? '')) !!}</small>
    <span>{{ $partner['name'] ?? '' }}</span>
</div>
