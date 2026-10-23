{{--
    Default pagination (plain Laravel / link-based) — the same look as the
    catalogue pager but with real <a href> links, for non-Livewire lists such as
    the blog. Our own classes (app.css), so it stays high-contrast and on-brand
    regardless of the visitor's OS dark-mode (the framework default ships `dark:`
    variants that render dark pills on our light UI).
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="pager">
        <p class="pager-count">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </p>

        <ul class="pager-list">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <li><span class="pager-link pager-arrow is-disabled" aria-hidden="true">‹</span></li>
            @else
                <li><a class="pager-link pager-arrow" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">‹</a></li>
            @endif

            {{-- Numbers & "…" --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="pager-dots" aria-hidden="true">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li><span class="pager-link is-active" aria-current="page">{{ $page }}</span></li>
                        @else
                            <li><a class="pager-link" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <li><a class="pager-link pager-arrow" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">›</a></li>
            @else
                <li><span class="pager-link pager-arrow is-disabled" aria-hidden="true">›</span></li>
            @endif
        </ul>
    </nav>
@endif
