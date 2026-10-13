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

    <div class="credential-grid">
        @foreach ($partners as $partner)
            <div>
                <strong>{{ $partner['code'] ?? '' }}</strong>
                {{-- Escaped first, then nl2br: the badge is client copy, so it is
                     never trusted as HTML. --}}
                <small>{!! nl2br(e($partner['badge'] ?? '')) !!}</small>
                <span>{{ $partner['name'] ?? '' }}</span>
            </div>
        @endforeach
    </div>
</section>
