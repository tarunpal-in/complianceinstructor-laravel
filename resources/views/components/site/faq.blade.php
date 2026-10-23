@props(['items' => [], 'heading' => 'Frequently asked questions'])

@php
    $items = collect($items ?? [])
        ->filter(fn ($item): bool => filled($item['question'] ?? null) && filled($item['answer'] ?? null));
@endphp

{{-- The same native-<details> accordion as the page-builder FAQ block: works
     without JavaScript and is keyboard-accessible (§9). Renders nothing when
     there are no questions. --}}
@if ($items->isNotEmpty())
    <section class="section pb-faq">
        @if (filled($heading))
            <div class="section-heading centered"><h2>{{ $heading }}</h2></div>
        @endif

        <div class="pb-faq-list">
            @foreach ($items as $item)
                <details class="pb-faq-item">
                    <summary>{{ $item['question'] }}</summary>
                    <p>{{ $item['answer'] }}</p>
                </details>
            @endforeach
        </div>
    </section>
@endif
