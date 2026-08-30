@props(['steps' => []])

<section class="process section" id="about">
    <div class="section-heading centered">
        <div class="eyebrow">SIMPLE PROCESS</div>
        <h2>How It Works</h2>
        <p>From registration to certification in three straightforward steps.</p>
    </div>

    <div class="process-grid">
        @foreach ($steps as $step)
            <article class="process-card">
                <span class="step-number">{{ $step['number'] }}</span>
                <div class="process-icon"><x-ui.icon :name="$step['icon']" :size="23" /></div>
                <h3>{{ $step['title'] }}</h3>
                <p>{{ $step['body'] }}</p>
            </article>
        @endforeach
    </div>
</section>
