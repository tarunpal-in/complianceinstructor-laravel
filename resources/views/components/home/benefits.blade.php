@props(['benefits' => []])

<section class="benefits">
    <div class="benefit-grid">
        @foreach ($benefits as $benefit)
            <div class="benefit">
                <span><x-ui.icon :name="$benefit['icon']" :size="26" /></span>
                <div>
                    <h3>{{ $benefit['title'] }}</h3>
                    <p>{{ $benefit['body'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>
