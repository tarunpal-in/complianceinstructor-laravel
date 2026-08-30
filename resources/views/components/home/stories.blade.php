@props(['stories' => [], 'rating' => '4.8', 'reviews' => 312])

<section class="stories section">
    <div class="section-heading split">
        <div>
            <div class="eyebrow">WHAT PROFESSIONALS SAY</div>
            <h2>Success Stories</h2>
        </div>
        <div class="review-pill">
            <span aria-hidden="true">★★★★★</span> <strong>{{ $rating }}</strong> from {{ number_format($reviews) }} reviews
        </div>
    </div>

    <div class="story-grid">
        @foreach ($stories as $story)
            <article class="story-card">
                <span aria-label="Rated 5 out of 5">★★★★★</span>
                <p>“{{ $story['quote'] }}”</p>
                <div class="story-person">
                    <div>{{ $story['initials'] }}</div>
                    <span>
                        <strong>{{ $story['name'] }}</strong>
                        <small>{{ $story['role'] }}</small>
                    </span>
                </div>
            </article>
        @endforeach
    </div>
</section>
