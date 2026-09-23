{{-- The ask. --}}
<section @class(['section', 'pb-block', 'pb-cta', 'quiet' => $block->value('tone') === 'quiet'])>
    <div>
        <strong>{{ $block->value('heading') }}</strong>
        @if ($body = $block->value('body'))
            <span>{{ $body }}</span>
        @endif
    </div>

    <a class="pb-button" href="{{ $block->value('button_url') }}">{{ $block->value('button_label') }}</a>
</section>
