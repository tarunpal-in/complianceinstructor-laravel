@props(['benefits' => null])

@php
    $benefits = $benefits ?: [
        ['icon' => 'users-round', 'title' => 'Expert-Led Online Sessions', 'body' => 'Learn from certified practitioners with 15+ years of real-world compliance experience.'],
        ['icon' => 'monitor', 'title' => 'Flexible Learning Options', 'body' => 'Attend live webinars in real-time or watch recordings on your own schedule — 24/7 access.'],
        ['icon' => 'award', 'title' => 'Career-Boosting Certifications', 'body' => 'Earn HRCI, SHRM, and NASBA/CPE credits that count toward professional recertification.'],
    ];
@endphp

<section class="benefits">
    <div class="benefit-grid">
        @foreach ($benefits as $benefit)
            <div class="benefit">
                <span><x-ui.icon :name="$benefit['icon'] ?? ''" :size="26" /></span>
                <div>
                    <h3>{{ $benefit['title'] ?? '' }}</h3>
                    <p>{{ $benefit['body'] ?? '' }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>
