@props([
    'webinars' => null,
    'eyebrow' => null,
    'heading' => null,
    'intro' => null,
    'linkLabel' => null,
    'linkUrl' => null,
])

@php
    $eyebrow = $eyebrow ?: 'LIVE EVENTS';
    $heading = $heading ?: 'Upcoming Webinars';
    $intro = $intro ?: 'Expert-led sessions with recognized CEU credits. Register today.';
    $linkLabel = $linkLabel ?: 'All Upcoming Webinars';
    $linkUrl = $linkUrl ?: '#webinars';

    // Default placeholders for the unseeded-install fallback. Dates derive the
    // badge and body from one Carbon instance, so they cannot drift.
    if (! $webinars) {
        $base = \Illuminate\Support\Carbon::create(2026, 9, 22, 10, 0);
        $webinars = collect([
            ['title' => 'FMLA Compliance 2026: Avoiding Costly Mistakes', 'speaker' => 'Sandra Torres, SHRM–SCP', 'speaker_title' => 'HR Compliance Specialist', 'speaker_initials' => 'ST', 'accreditations' => ['HRCI', 'SHRM']],
            ['title' => 'Payroll Year-End Close: What Changed for 2026', 'speaker' => 'Marcus Reed, CPP', 'speaker_title' => 'Payroll Operations Lead', 'speaker_initials' => 'MR', 'accreditations' => ['CPE', 'APA']],
            ['title' => 'OSHA Recordkeeping for Manufacturing Teams', 'speaker' => 'Dr. Alicia Nwosu', 'speaker_title' => 'EHS Director', 'speaker_initials' => 'AN', 'accreditations' => ['HRCI', 'SHRM']],
            ['title' => 'Wage & Hour Audits: Preparing Before the Letter', 'speaker' => 'David Kaplan, JD', 'speaker_title' => 'Employment Counsel', 'speaker_initials' => 'DK', 'accreditations' => ['HRCI', 'SHRM']],
        ])->map(fn (array $webinar, int $i): array => [
            ...$webinar,
            'starts_at' => $base->copy()->addDays($i * 7),
            'time_label' => '10:00 AM PT / 1:00 PM ET',
            'duration' => '90 Min',
            'url' => null,
        ])->all();
    }
@endphp

<section class="webinars section" id="training">
    <div class="section-heading split">
        <div>
            <div class="eyebrow">{{ $eyebrow }}</div>
            <h2>{{ $heading }}</h2>
            <p>{{ $intro }}</p>
        </div>
        <a class="text-link" href="{{ $linkUrl }}">{{ $linkLabel }} <x-ui.icon name="arrow-right" :size="16" /></a>
    </div>

    <div class="webinar-grid" id="webinars">
        @foreach ($webinars as $webinar)
            @php $starts = $webinar['starts_at']; @endphp

            <article class="webinar-card">
                <div class="webinar-photo">
                    <img src="{{ asset('images/home/workshop.jpg') }}" alt="Audience at a webinar" width="940" height="628" loading="lazy" decoding="async">

                    {{-- Badge and body date derive from one Carbon instance. The
                         comp badges "26 AUG" on a card whose body reads "Aug 22";
                         two fields drifting is exactly what this prevents. --}}
                    <div class="date-badge">
                        <x-ui.icon name="calendar-days" :size="16" />
                        <strong>{{ $starts->format('d') }}</strong>
                        <span>{{ Str::upper($starts->format('M')) }}</span>
                    </div>

                    <span class="live-badge">● LIVE WEBINAR</span>

                    <div class="photo-tags">
                        @foreach ($webinar['accreditations'] as $code)<b>{{ $code }}</b>@endforeach
                    </div>
                </div>

                <div class="webinar-body">
                    <h3>{{ $webinar['title'] }}</h3>

                    <div class="webinar-meta">
                        <p>
                            <x-ui.icon name="calendar-days" :size="24" />
                            <time datetime="{{ $starts->toIso8601String() }}">{{ $starts->format('M j, Y') }} · {{ $webinar['time_label'] }}</time>
                        </p>

                        <p><x-ui.icon name="clock-3" :size="24" /> {{ $webinar['duration'] }} <em>◉ &nbsp;LIVE WEBINAR</em></p>
                    </div>

                    <div class="speaker">
                        <div class="speaker-avatar">{{ $webinar['speaker_initials'] }}</div>
                        <div>
                            <b><x-ui.icon name="user-round" :size="15" /> Speaker</b>
                            <strong>{{ $webinar['speaker'] }}</strong>
                            <small>{{ $webinar['speaker_title'] }}</small>
                        </div>
                    </div>

                    <button @if (! empty($webinar['url'])) @click="window.location.href = '{{ $webinar['url'] }}'" @else @click="showNotice('Webinar details opened')" @endif>View Details</button>
                </div>
            </article>
        @endforeach
    </div>
</section>
