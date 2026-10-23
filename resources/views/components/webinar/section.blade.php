@props(['id', 'heading' => '', 'body'])

@php($body = trim((string) $body))

{{-- scroll-margin-top in CSS keeps the heading clear of the sticky tab bar when
     an anchor is followed; without it the tab bar covers what you jumped to. --}}
<section class="wd-section" id="{{ $id }}" tabindex="-1">
    @if (trim((string) $heading) !== '')
        <h2>{{ $heading }}</h2>
    @endif

    @if (preg_match('/<\/?[a-z][^>]*>/i', $body))
        {{-- Rich HTML, as the WordPress import stored it. Re-sanitised at render
             through the same allowlist (App\Support\Html) so markup is safe
             whatever its source, then printed as formatted prose. --}}
        <div class="wd-prose">{!! \App\Support\Html::sanitize($body) !!}</div>
    @else
        {{-- Plain text typed into a textarea: paragraph-per-blank-line, escaped. --}}
        @foreach (preg_split('/\R{2,}/', $body) as $paragraph)
            <p>{{ trim($paragraph) }}</p>
        @endforeach
    @endif
</section>
