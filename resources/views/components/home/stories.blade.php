@props([
    'eyebrow' => null,
    'heading' => null,
    'stories' => null,
    'rating' => null,
    'reviews' => null,
])

@php
    $eyebrow = $eyebrow ?: 'WHAT PROFESSIONALS SAY';
    $heading = $heading ?: 'Success Stories';
    $rating = $rating ?: '4.8';
    $reviews = $reviews ?: 312;
    $stories = $stories ?: [
        ['quote' => "Jason's H.O.P.E. framework gave me a concrete language to describe what great leadership looks like. I implemented the Ownership pillar the following week and saw an immediate shift in team accountability.", 'name' => 'Sandra Mitchell', 'role' => 'HR Director · BlueCross Health', 'initials' => 'SM'],
        ['quote' => 'One of the most practical webinars I have attended. The Q&A was genuinely useful, the slides are clean and reference-able, and the CPE certificate was in my inbox within minutes.', 'name' => 'Marcus Okafor', 'role' => 'VP of People Operations · FinServe Inc.', 'initials' => 'MO'],
        ['quote' => 'We send our whole compliance team now. Buying seats on one invoice and having everyone credited individually was the thing that made it work for us.', 'name' => 'Priya Sharma', 'role' => 'L&D Manager · GlobalTech Solutions', 'initials' => 'PS'],
    ];
@endphp

<section class="stories section">
    <div class="section-heading split">
        <div>
            <div class="eyebrow">{{ $eyebrow }}</div>
            <h2>{{ $heading }}</h2>
        </div>
        <div class="review-pill">
            <span aria-hidden="true">★★★★★</span> <strong>{{ $rating }}</strong> from {{ number_format((int) $reviews) }} reviews
        </div>
    </div>

    <div class="story-grid">
        @foreach ($stories as $story)
            <article class="story-card">
                <span aria-label="Rated 5 out of 5">★★★★★</span>
                <p>“{{ $story['quote'] ?? '' }}”</p>
                <div class="story-person">
                    <div>{{ $story['initials'] ?? '' }}</div>
                    <span>
                        <strong>{{ $story['name'] ?? '' }}</strong>
                        <small>{{ $story['role'] ?? '' }}</small>
                    </span>
                </div>
            </article>
        @endforeach
    </div>
</section>
