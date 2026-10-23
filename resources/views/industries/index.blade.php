@extends('layouts.public')


@section('content')
    {{-- Editable hero (Core Pages → Industries). --}}
    <x-site.page-hero :page="$corePage" />

    <section class="section ind-grid-section">
        @if (empty($industries))
            <p class="ind-empty">Industry pages are coming soon.</p>
        @else
            {{-- The same category card the homepage uses — each links to that
                 industry's landing page (/industry/{slug}). --}}
            <div class="category-grid" style="--cat-cols: {{ min(max(count($industries), 1), 4) }}">
                @foreach ($industries as $category)
                    @include('home.partials.category-card', ['category' => $category])
                @endforeach
            </div>
        @endif
    </section>

    <x-site.copy-blocks :sections="$corePage?->sections" />

    <x-site.faq :items="$corePage?->faqItems() ?? []" />
@endsection
