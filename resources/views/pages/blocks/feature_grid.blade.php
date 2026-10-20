{{-- Three or four things the business wants to say at once. --}}
<section class="section pb-block pb-features">
    @if (($heading = $block->value('heading')) || ($intro = $block->value('intro')))
        <div class="section-heading centered">
            @if ($heading)<h2>{{ $heading }}</h2>@endif
            @if ($intro ?? null)<p>{{ $intro }}</p>@endif
        </div>
    @endif

    <div class="pb-feature-grid">
        @foreach ($block->rows('features') as $feature)
            <article class="pb-feature">
                @if (filled($feature['icon'] ?? null))
                    <span class="pb-feature-icon"><x-ui.icon :name="$feature['icon']" :size="22" /></span>
                @endif
                <h3>{{ $feature['title'] ?? '' }}</h3>
                @if (filled($feature['body'] ?? null))
                    <p>{{ $feature['body'] }}</p>
                @endif
            </article>
        @endforeach
    </div>
</section>
