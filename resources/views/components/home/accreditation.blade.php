@props(['partners' => []])

<section class="accreditation section">
    <div class="section-heading centered">
        <div class="eyebrow">TRUSTED CREDENTIALS</div>
        <h2>Accreditation Partners</h2>
        {{-- The comp reads "Every CourseMinistry webinar…" — the predecessor's
             name. Bound to config('app.name') so it cannot ship wrong. --}}
        <p>Every {{ config('app.name') }} webinar carries credits recognized by the industry's leading professional certification bodies.</p>
    </div>

    <div class="credential-grid">
        @foreach ($partners as $partner)
            <div>
                <strong>{{ $partner['code'] }}</strong>
                <small>{!! $partner['badge'] !!}</small>
                <span>{{ $partner['name'] }}</span>
            </div>
        @endforeach
    </div>
</section>
