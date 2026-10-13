@props([
    'speakers' => null,
    'eyebrow' => null,
    'heading' => null,
    'intro' => null,
    'buttonLabel' => null,
    'buttonUrl' => null,
])

@php
    $eyebrow = $eyebrow ?: 'OUR INSTRUCTORS';
    $heading = $heading ?: 'Experienced Faculty';
    $intro = $intro ?: "Our speakers have argued the cases, run the audits and built the programs. They bring the edge cases you won't find in a compliance handbook — and they stay on the line until your question is answered.";
    $buttonLabel = $buttonLabel ?: 'Meet the Speakers';
    $buttonUrl = $buttonUrl ?: '#speakers';

    $speakers = $speakers ?: [
        ['name' => 'Diane Whitfield', 'credentials' => 'SPHR · Former regional HR director, Fortune 100 retail', 'bodies' => 'HRCI · SHRM', 'image' => 'seminar'],
        ['name' => 'Marcus Reed', 'credentials' => 'CPP · 18 years in multi-state payroll operations', 'bodies' => 'CPE · APA', 'image' => 'office'],
        ['name' => 'Dr. Alicia Nwosu', 'credentials' => 'CSP · EHS director across three manufacturing plants', 'bodies' => 'HRCI', 'image' => 'workshop'],
        ['name' => 'David Kaplan', 'credentials' => 'JD · Employment counsel, wage & hour defence', 'bodies' => 'HRCI · SHRM', 'image' => 'laptop'],
    ];

    // Two columns scrolling in opposite directions. The right-hand column is
    // rotated by two so the same face is never level with itself across the
    // gutter — with only a few speakers, unrotated columns read as a mirror.
    $rotated = array_merge(array_slice($speakers, 2), array_slice($speakers, 0, 2));

    $columns = [
        ['direction' => 'down', 'speakers' => $speakers],
        ['direction' => 'up', 'speakers' => $rotated],
    ];
@endphp

<section class="faculty section" id="speakers">
    <div class="faculty-copy">
        <div class="eyebrow">{{ $eyebrow }}</div>
        <h2>{{ $heading }}</h2>
        <p>{{ $intro }}</p>
        <button class="button primary" @click="window.location.href = '{{ $buttonUrl }}'">{{ $buttonLabel }}</button>
    </div>

    <div class="faculty-cards">
        @foreach ($columns as $column)
            <div class="faculty-column">
                <div class="faculty-track is-{{ $column['direction'] }}">
                    {{-- Each set is rendered twice. The keyframes translate by
                         exactly half the track height, so the second set lands
                         where the first began and the loop has no seam. The
                         duplicate is aria-hidden so a screen reader does not
                         read every speaker a second time. --}}
                    @foreach ([false, true] as $isDuplicate)
                        @foreach ($column['speakers'] as $speaker)
                            @php $image = $speaker['image_url'] ?? asset('images/home/'.($speaker['image'] ?? 'seminar').'.jpg'); @endphp

                            <article class="faculty-card" @if ($isDuplicate) aria-hidden="true" @endif>
                                <img
                                    src="{{ $image }}"
                                    alt="{{ $isDuplicate ? '' : $speaker['name'] }}"
                                    width="940"
                                    height="628"
                                    loading="lazy"
                                >
                                <h3>{{ $speaker['name'] }}</h3>
                                <p>{{ $speaker['credentials'] }}</p>
                                <a href="#speakers" @if ($isDuplicate) tabindex="-1" @endif>{{ $speaker['bodies'] }}</a>
                            </article>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</section>
