@props(['stats' => [], 'bodies' => []])

<section class="hero" id="home">
    <div class="hero-inner">
        <div class="hero-copy">
            <div class="eyebrow gold">• #1 COMPLIANCE TRAINING PLATFORM</div>

            <h1>Stay Ahead — <span>Learn from</span><br>the Best.</h1>

            <p class="hero-lede">Access live and recorded compliance webinars led by certified industry experts. Earn HRCI, SHRM, and NASBA/CPE recertification credits — complete with study materials, certificates, and transcripts.</p>

            <div class="hero-buttons">
                <button class="button primary" @click="showNotice('Welcome to your learning journey')">Start Learning Now</button>
                <a class="button secondary" href="#webinars">View Courses</a>
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
