{{-- The ask. The blue band is an inner element so the section keeps the normal
     block spacing above and below it (otherwise the band's own padding was the
     only spacing, leaving it flush against whatever came next). --}}
<section class="section pb-block pb-cta-block">
    <div @class(['pb-cta', 'quiet' => $block->value('tone') === 'quiet'])>
        <div>
            <strong>{{ $block->value('heading') }}</strong>
            @if ($body = $block->value('body'))
                <span>{{ $body }}</span>
            @endif
        </div>

        <a class="pb-button" href="{{ $block->value('button_url') }}">{{ $block->value('button_label') }}</a>
    </div>
</section>
