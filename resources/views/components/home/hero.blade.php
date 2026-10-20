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
    'imageUrls' => null,
    'liveDates' => null,
    'featureCards' => null,
    'accreditations' => null,
    'accreditationSuffix' => null,
    'accreditationNote' => null,
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

    // Real upcoming live-webinar dates for the rotating badge; resolved here so a
    // bare <x-home.hero /> (the unseeded fallback) still shows live data.
    $liveDates = $liveDates ?? \App\Support\HomepageContent::liveWebinarDates();
    $liveDates = ! empty($liveDates) ? array_values($liveDates) : ['Scheduling soon'];

    // The rotating "Certificate Ready" card. Defaults describe the platform, so
    // a bare <x-home.hero /> still shows a populated, rotating badge.
    $featureCards = $featureCards ?: [
        ['title' => 'Certificate Ready', 'subtitle' => 'Instant download'],
        ['title' => 'Expert-Led Sessions', 'subtitle' => 'Certified instructors'],
        ['title' => 'On-Demand Access', 'subtitle' => 'Watch anytime, anywhere'],
        ['title' => 'CEU Credits', 'subtitle' => 'Earn & maintain compliance'],
    ];
    $featureCards = array_values($featureCards);

    // The rotating "Approved" card cycles through the accrediting bodies.
    $accreditations = $accreditations ?? \App\Support\HomepageContent::accreditationCodes();
    $accreditations = ! empty($accreditations) ? array_values($accreditations) : ['HRCI', 'SHRM', 'CPE', 'APA'];
    $accreditationSuffix = $accreditationSuffix ?: 'Approved';
    $accreditationNote = $accreditationNote ?: 'Industry Accredited';

    // One image shows on its own; several crossfade as a slider. Falls back to
    // the shipped default when the admin has uploaded none.
    $heroImages = ! empty($imageUrls) ? array_values($imageUrls) : [asset('images/home/seminar.jpg')];
@endphp

<section class="hero" id="home">
    {{-- Decorative dot field. Seven clusters scattered behind the content, as
         marked up in the comp — purely ornamental, so the whole layer is inert
         to pointers and hidden from assistive tech. --}}
    <div class="hero-decor" aria-hidden="true">
        <span class="dot-grid d1"></span>
        <span class="dot-grid d2"></span>
        <span class="dot-grid d3"></span>
        <span class="dot-grid d5"></span>
        <span class="dot-grid d7"></span>

        {{-- Single scattered flecks near the content — a mix of gold and slate,
             as in the comp (not paired dots). --}}
        <span class="dot-fleck gold f1"></span>
        <span class="dot-fleck slate f2"></span>
        <span class="dot-fleck gold f3"></span>
        <span class="dot-fleck slate f4"></span>
        <span class="dot-fleck gold f5"></span>
        <span class="dot-fleck slate f6"></span>
        <span class="dot-fleck gold f7"></span>
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
            <div class="hero-image-wrap">
                @if (count($heroImages) > 1)
                    {{-- Several images crossfade on a timer; the first is shown
                         immediately (is-active) so there is no flash of nothing
                         before Alpine starts. --}}
                    <div class="hero-slides" x-data="heroSlides({ count: {{ count($heroImages) }} })">
                        @foreach ($heroImages as $index => $src)
                            <img src="{{ $src }}"
                                 alt="Professionals in a compliance seminar"
                                 width="940" height="628"
                                 @if ($index > 0) loading="lazy" @endif
                                 class="hero-slide @if ($index === 0) is-active @endif"
                                 :class="{ 'is-active': i === {{ $index }} }">
                        @endforeach
                    </div>
                @else
                    <img src="{{ $heroImages[0] }}" alt="Professionals in a compliance seminar" width="940" height="628">
                @endif
                <div class="image-overlay"></div>
            </div>

            {{-- The certificate card rotates through the platform's feature
                 highlights (admin-managed); a single item simply does not
                 rotate. The staggered start keeps the three cards from all
                 flipping on the same beat. --}}
            <div class="float-card certificate"
                 x-data="{ items: @js($featureCards), i: 0, swapping: false }"
                 x-init="items.length > 1 && setTimeout(() => setInterval(() => {
                     swapping = true;
                     setTimeout(() => { i = (i + 1) % items.length; swapping = false; }, 220);
                 }, 3500), 0)">
                <span class="mini-icon blue"><x-ui.icon name="award" :size="19" /></span>
                <div>
                    <strong class="float-swap" :class="{ 'is-swapping': swapping }" x-text="items[i].title">{{ $featureCards[0]['title'] }}</strong>
                    <small class="float-swap" :class="{ 'is-swapping': swapping }" x-text="items[i].subtitle">{{ $featureCards[0]['subtitle'] }}</small>
                </div>
            </div>

            {{-- The approved card rotates through every active accrediting body
                 (dynamic), e.g. "HRCI Approved", "SHRM Approved". --}}
            <div class="float-card approved"
                 x-data="{ codes: @js($accreditations), suffix: @js($accreditationSuffix), i: 0, swapping: false }"
                 x-init="codes.length > 1 && setTimeout(() => setInterval(() => {
                     swapping = true;
                     setTimeout(() => { i = (i + 1) % codes.length; swapping = false; }, 220);
                 }, 3500), 1750)">
                <span class="mini-icon blue"><x-ui.icon name="shield-check" :size="19" /></span>
                <div>
                    <strong class="float-swap" :class="{ 'is-swapping': swapping }" x-text="codes[i] + ' ' + suffix">{{ $accreditations[0] }} {{ $accreditationSuffix }}</strong>
                    <small>{{ $accreditationNote }}</small>
                </div>
            </div>

            {{-- The "Next:" date cycles through the upcoming live-webinar dates
                 every few seconds. The dates are real (resolved above); a single
                 date simply does not rotate. --}}
            <div class="float-card live"
                 x-data="{ dates: @js($liveDates), i: 0, swapping: false }"
                 x-init="dates.length > 1 && setInterval(() => {
                     swapping = true;
                     setTimeout(() => { i = (i + 1) % dates.length; swapping = false; }, 220);
                 }, 3500)">
                <span class="mini-icon green"><x-ui.icon name="star" :size="19" /></span>
                <div>
                    <strong>Live Webinars Weekly</strong>
                    <small>Next:
                        <span class="hero-live-date" :class="{ 'is-swapping': swapping }" x-text="dates[i]">{{ $liveDates[0] }}</span>
                    </small>
                </div>
            </div>

            <div class="rating-card">
                <strong>4.9</strong><span>★★★★★</span><small>Rating</small>
            </div>
        </div>
    </div>
</section>
