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

    {{-- A continuous slider of every accrediting body, the same marquee pattern
         the faculty section uses. The set is rendered twice and the track moves
         by exactly half its width, so the loop has no seam; the duplicate is
         aria-hidden so a screen reader reads each body once. Pauses on hover. --}}
    <div class="credential-marquee">
        <div class="credential-track">
            @foreach ([false, true] as $isDuplicate)
                @foreach ($partners as $partner)
                    <div class="credential-card" @if ($isDuplicate) aria-hidden="true" @endif>
                        {{-- The uploaded logo when the body has one, else its code set
                             in the brand type — so a body with no logo yet still reads. --}}
                        @if (! empty($partner['logo_url']))
                            <img class="credential-logo" src="{{ $partner['logo_url'] }}"
                                 alt="{{ $isDuplicate ? '' : ($partner['name'] ?? ($partner['code'] ?? '')) }}" loading="lazy">
                        @else
                            <strong>{{ $partner['code'] ?? '' }}</strong>
                        @endif
                        {{-- Escaped first, then nl2br: the badge is client copy, so it
                             is never trusted as HTML. --}}
                        <small>{!! nl2br(e($partner['badge'] ?? '')) !!}</small>
                        <span>{{ $partner['name'] ?? '' }}</span>
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
</section>
