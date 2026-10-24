@props(['webinar'])

{{-- The type crumb links to the catalogue filtered to that format, the industry
     crumb to its landing page. Only the current page (the title) is plain text. --}}
<nav class="wd-crumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="{{ route('home') }}">Home</a></li>
        <li><a href="{{ route('webinars.index', ['type' => $webinar->type->value]) }}">{{ $webinar->type->label() }}s</a></li>
        @if ($webinar->industry)
            <li><a href="{{ route('industries.show', $webinar->industry) }}">{{ $webinar->industry->name }}</a></li>
        @endif
        <li><span aria-current="page">{{ $webinar->title }}</span></li>
    </ol>
</nav>
