{{--
    On-page breadcrumbs — client SEO brief §8, and the visible half of the
    BreadcrumbList schema (they are built from the same array in the controller,
    so what a visitor sees and what Google reads can never disagree).

    The last crumb is the current page: shown as text, not a link, and marked
    aria-current so a screen reader announces it.
--}}
@if (($breadcrumbs ?? []) !== [])
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <ol>
            @foreach ($breadcrumbs as $crumb)
                @if (! $loop->last)
                    <li><a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a></li>
                @else
                    <li aria-current="page">{{ $crumb['name'] }}</li>
                @endif
            @endforeach
        </ol>
    </nav>
@endif
