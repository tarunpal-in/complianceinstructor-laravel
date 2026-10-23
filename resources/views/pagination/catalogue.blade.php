{{--
    Catalogue pagination (Livewire) — CLAUDE.md §6 P2.

    Replaces the framework's default Tailwind pager, whose `dark:` utility
    variants render dark pills on our fixed-light UI whenever the visitor's OS is
    in dark mode. This uses our own classes (styled in app.css) so it stays
    high-contrast and on-brand in either theme. Buttons drive Livewire's own
    page methods, so paging stays in-place (no reload).
--}}
<div>
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
                    <li>
                        <button type="button" class="pager-link pager-arrow" rel="prev" aria-label="Previous page"
                            wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled">‹</button>
                    </li>
                @endif

                {{-- Numbers & "…" --}}
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li><span class="pager-dots" aria-hidden="true">{{ $element }}</span></li>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <li wire:key="pager-{{ $paginator->getPageName() }}-{{ $page }}">
                                @if ($page == $paginator->currentPage())
                                    <span class="pager-link is-active" aria-current="page">{{ $page }}</span>
                                @else
                                    <button type="button" class="pager-link" aria-label="Go to page {{ $page }}"
                                        wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled">{{ $page }}</button>
                                @endif
                            </li>
                        @endforeach
                    @endif
                @endforeach

                {{-- Next --}}
                @if ($paginator->hasMorePages())
                    <li>
                        <button type="button" class="pager-link pager-arrow" rel="next" aria-label="Next page"
                            wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled">›</button>
                    </li>
                @else
                    <li><span class="pager-link pager-arrow is-disabled" aria-hidden="true">›</span></li>
                @endif
            </ul>
        </nav>
    @endif
</div>
