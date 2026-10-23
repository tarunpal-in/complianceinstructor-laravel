@props(['sections' => null])

{{-- A core page's "extra copy blocks": the visible ones, in order, each an
     optional heading over sanitised rich text (§9 — never raw). Renders nothing
     when there are none, so a page with an empty content area shows no band. --}}
@php($visible = $sections?->where('is_visible', true) ?? collect())

@if ($visible->isNotEmpty())
    <section class="section site-copy">
        @foreach ($visible as $block)
            <div class="site-copy-block">
                @if ($block->hasHeading())
                    <h2>{{ $block->heading }}</h2>
                @endif

                <div class="pb-prose">{!! \App\Support\Html::sanitize($block->body) !!}</div>
            </div>
        @endforeach
    </section>
@endif
