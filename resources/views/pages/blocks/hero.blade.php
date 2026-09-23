{{-- The band at the top of a landing page. --}}
<section class="section pb-block pb-hero">
    <div class="section-heading centered">
        @if ($eyebrow = $block->value('eyebrow'))
            <div class="eyebrow">{{ $eyebrow }}</div>
        @endif

        <h1 class="pb-hero-heading">{{ $block->value('heading') }}</h1>

        @if ($lede = $block->value('lede'))
            <p>{{ $lede }}</p>
        @endif
    </div>

    @if (($label = $block->value('button_label')) && ($url = $block->value('button_url')))
        <p class="pb-actions"><a class="pb-button" href="{{ $url }}">{{ $label }}</a></p>
    @endif
</section>
