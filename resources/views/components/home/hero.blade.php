@props([
    'eyebrow' => null,
    'headingLead' => null,
    'headingHighlight' => null,
    'headingTail' => null,
    'lede' => null,
    'primaryLabel' => null,
    'secondaryLabel' => null,
    'secondaryUrl' => null,
    'stats' => null,
    'bodies' => null,
])

@php
    // Defaults match the comp exactly, so rendering this component with no props
    // — the fallback when the homepage has not been seeded — is pixel-identical
    // to what shipped. `?:` rather than `??` so an empty field also falls back,
    // keeping the front door from ever rendering a blank headline.
    $eyebrow = $eyebrow ?: '#1 COMPLIANCE TRAINING PLATFORM';
    $headingLead = $headingLead ?: 'Stay Ahead —';
    $headingHighlight = $headingHighlight ?: 'Learn from';
    $headingTail = $headingTail ?: 'the Best.';
    $lede = $lede ?: 'Access live and recorded compliance webinars led by certified industry experts. Earn HRCI, SHRM, and NASBA/CPE recertification credits — complete with study materials, certificates, and transcripts.';
    $primaryLabel = $primaryLabel ?: 'Start Learning Now';
    $secondaryLabel = $secondaryLabel ?: 'View Courses';
    $secondaryUrl = $secondaryUrl ?: '#webinars';
    $stats = $stats ?: [
        ['value' => '1,200+', 'label' => 'Webinars Delivered'],
        ['value' => '48,000+', 'label' => 'Professionals Trained'],
        ['value' => '4.9★', 'label' => 'Average Rating'],
    ];
    $bodies = $bodies ?: ['HRCI', 'SHRM', 'CPE'];
@endphp

<section class="hero" id="home">
    {{-- Decorative dot field. Seven clusters scattered behind the content, as
         marked up in the comp — purely ornamental, so the whole layer is inert
         to pointers and hidden from assistive tech. --}}
    <div class="hero-decor" aria-hidden="true">
        <span class="dot-grid d1"></span>
        <span class="dot-grid d2"></span>
        <span class="dot-grid d3"></span>
        <span class="dot-grid d4"></span>
        <span class="dot-grid d5"></span>
        <span class="dot-grid d6"></span>
        <span class="dot-grid d7"></span>
    </div>

    <div class="hero-inner">
        <div class="hero-copy">
            <div class="eyebrow gold">• {{ $eyebrow }}</div>

            <h1>{{ $headingLead }} <span>{{ $headingHighlight }}</span><br>{{ $headingTail }}</h1>

            <p class="hero-lede">{{ $lede }}</p>

            <div class="hero-buttons">
                <button class="button primary" @click="showNotice('Welcome to your learning journey')">{{ $primaryLabel }}</button>
                <a class="button secondary" href="{{ $secondaryUrl }}">{{ $secondaryLabel }}</a>
            </div>

            {{-- §5.6 `settings` owns these figures in P6. The comp's own 4.9 here
                 against 4.8 in Success Stories is what hardcoding twice costs. --}}
            <div class="stats">
                @foreach ($stats as $stat)
                    <div><strong>{{ $stat['value'] }}</strong><span>{{ $stat['label'] }}</span></div>
                @endforeach
            </div>

            <div class="recognized">
                <span>Recognized by:</span>
                <div>@foreach ($bodies as $body)<b>{{ $body }}</b>@endforeach</div>
            </div>
        </div>

        <div class="hero-visual">
            <div class="dot-grid hero-dots"></div>

            <div class="hero-image-wrap">
                <img src="{{ asset('images/home/seminar.jpg') }}" alt="Professionals in a compliance seminar" width="940" height="628">
                <div class="image-overlay"></div>
            </div>

            <div class="float-card certificate">
                <span class="mini-icon blue"><x-ui.icon name="award" :size="19" /></span>
                <div><strong>Certificate Ready</strong><small>Instant download</small></div>
            </div>

            <div class="float-card approved">
                <span class="mini-icon blue"><x-ui.icon name="shield-check" :size="19" /></span>
                <div><strong>HRCI Approved</strong><small>Industry Accredited</small></div>
            </div>

            <div class="float-card live">
                <span class="mini-icon green"><x-ui.icon name="star" :size="19" /></span>
                <div><strong>Live Webinars Weekly</strong><small>Next: Aug 22, 2025</small></div>
            </div>

            <div class="rating-card">
                <strong>4.9</strong><span>★★★★★</span><small>Rating</small>
            </div>
        </div>
    </div>
</section>
