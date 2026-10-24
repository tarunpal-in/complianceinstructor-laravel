@props([
    'eyebrow' => null,
    'heading' => null,
    'intro' => null,
    'partners' => null,
])

@php
    $eyebrow = $eyebrow ?: 'TRUSTED CREDENTIALS';
    $heading = $heading ?: 'Accreditation Partners';
    // The comp read "Every CourseMinistry webinar…" — the predecessor's name.
    // Bound to config('app.name') so the default cannot ship wrong.
    $intro = $intro ?: 'Every '.config('app.name').' webinar carries credits recognized by the industry\'s leading professional certification bodies.';
    // Badges carry a line break. Defaults use "\n" rather than <br> so the same
    // safe render — escape then nl2br — serves both the default and the copy a
    // client types in the editor.
    // Real active accrediting bodies (with their uploaded logos) wherever the
    // catalogue has any, so the section is dynamic on every render path. The
    // hardcoded set is the fallback for a brand-new install with none yet.
    // All active bodies (not just a handful), so the slider shows the full set.
    $partners = $partners ?: \App\Support\HomepageContent::accreditations(24);
    $partners = $partners ?: [
        ['code' => 'SHRM', 'badge' => "RECERTIFICATION\nPROVIDER", 'name' => 'Society for Human Resource Mgmt'],
        ['code' => 'CPE', 'badge' => "NATIONAL REGISTRY OF\nCPE SPONSORS", 'name' => 'Continuing Professional Education'],
        ['code' => 'HRCI', 'badge' => "RECERTIFICATION\nPROVIDER", 'name' => 'HR Certification Institute'],
    ];
@endphp

<section class="accreditation section">
    <div class="section-heading centered">
        <div class="eyebrow">{{ $eyebrow }}</div>
        <h2>{{ $heading }}</h2>
        <p>{{ $intro }}</p>
    </div>

    @php
        // Two identical groups translate by one group's width, so the loop has
        // no seam. Each group repeats the set enough to be wider than the strip
        // — otherwise a few logos leave the empty gap before the loop repeats.
        $count = max(1, count($partners));
        $repeat = (int) max(2, ceil(9 / $count));
        $loop = [];
        for ($r = 0; $r < $repeat; $r++) {
            foreach ($partners as $partner) {
                $loop[] = $partner;
            }
        }
        // A steady pace whatever the logo count — roughly one card every 1.8s.
        $duration = round(count($loop) * 1.8, 1);
    @endphp

    {{-- The scrolling marquee is decorative (the bodies are listed for assistive
         tech in .credential-list below), so it is aria-hidden. It is hidden
         outright on small screens and when motion is reduced. --}}
    <div class="credential-marquee" aria-hidden="true">
        @foreach ([0, 1] as $group)
            <div class="credential-track" style="animation-duration: {{ $duration }}s">
                @foreach ($loop as $partner)
                    <x-home.credential-card :partner="$partner" />
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- The real, readable list: visually hidden while the marquee scrolls, and
         shown as a plain centred row on small screens / reduced motion. --}}
    <div class="credential-list">
        @foreach ($partners as $partner)
            <x-home.credential-card :partner="$partner" />
        @endforeach
    </div>
</section>
