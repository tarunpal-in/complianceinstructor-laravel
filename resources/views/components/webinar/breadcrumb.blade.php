@props(['webinar'])

{{-- The middle two crumbs point at catalogue routes that land in P2. They are
     rendered as plain text until then rather than as links to '#', which would
     be announced as links by a screen reader and go nowhere. --}}
<nav class="wd-crumbs" aria-label="Breadcrumb">
    <ol>
        <li><a href="{{ route('home') }}">Home</a></li>
        <li><span>{{ $webinar->type->label() }}s</span></li>
        @if ($webinar->industry)
            <li><span>{{ $webinar->industry->name }}</span></li>
        @endif
        <li><span aria-current="page">{{ $webinar->title }}</span></li>
    </ol>
</nav>
