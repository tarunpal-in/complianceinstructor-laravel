@props([
    'eyebrow' => null,
    'heading' => null,
    'intro' => null,
    'steps' => null,
])

@php
    // Defaults match the comp, so a bare <x-home.process /> — the unseeded
    // fallback — renders exactly as it shipped.
    $eyebrow = $eyebrow ?: 'SIMPLE PROCESS';
    $heading = $heading ?: 'How It Works';
    $intro = $intro ?: 'From registration to certification in three straightforward steps.';
    $steps = $steps ?: [
        ['icon' => 'user-round', 'title' => 'Create Your Free Account', 'body' => 'Sign up in under 60 seconds. No credit card required. Access your learner dashboard immediately.'],
        ['icon' => 'monitor', 'title' => 'Explore Courses & Webinars', 'body' => 'Browse hundreds of live and recorded compliance webinars across HR, payroll, manufacturing, and more.'],
        ['icon' => 'star', 'title' => 'Enroll & Start Learning', 'body' => 'Attend live or on-demand, earn accredited CEU credits, and receive your certificate of completion.'],
    ];
@endphp

<section class="process section" id="about">
    <div class="section-heading centered">
        <div class="eyebrow">{{ $eyebrow }}</div>
        <h2>{{ $heading }}</h2>
        <p>{{ $intro }}</p>
    </div>

    <div class="process-grid">
        @foreach ($steps as $step)
            <article class="process-card">
                {{-- Numbered by position, so reordering in the editor renumbers
                     them rather than leaving "03" above the first card. --}}
                <span class="step-number">{{ sprintf('%02d', $loop->iteration) }}</span>
                <div class="process-icon"><x-ui.icon :name="$step['icon'] ?? ''" :size="23" /></div>
                <h3>{{ $step['title'] ?? '' }}</h3>
                <p>{{ $step['body'] ?? '' }}</p>
            </article>
        @endforeach
    </div>
</section>
