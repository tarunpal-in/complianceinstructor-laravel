{{-- Numbers the business leads with. --}}
<section class="section pb-block pb-stats">
    <div class="pb-stat-band">
        @foreach ($block->rows('stats') as $stat)
            <div class="pb-stat">
                <strong>{{ $stat['value'] ?? '' }}</strong>
                <span>{{ $stat['label'] ?? '' }}</span>
            </div>
        @endforeach
    </div>
</section>
