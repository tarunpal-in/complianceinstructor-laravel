{{-- Questions and answers. Native <details>, so it works without JavaScript
     and is keyboard-accessible for free (§9's WCAG pass in P9). --}}
<section class="section pb-block pb-faq">
    @if ($heading = $block->value('heading'))
        <div class="section-heading centered"><h2>{{ $heading }}</h2></div>
    @endif

    <div class="pb-faq-list">
        @foreach ($block->rows('questions') as $item)
            <details class="pb-faq-item">
                <summary>{{ $item['question'] ?? '' }}</summary>
                <p>{{ $item['answer'] ?? '' }}</p>
            </details>
        @endforeach
    </div>
</section>
