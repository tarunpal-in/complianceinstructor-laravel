@props(['webinar'])

@php
    // Falls back to the shared default so a webinar with no uploaded media still
    // shows a card image, like the catalogue and homepage cards.
    $image = $webinar->cardImageUrl() ?? $webinar->bannerImageUrl() ?? asset('images/home/workshop.jpg');
    $date = $webinar->scheduled_at?->timezone($webinar->timezone);
    $speaker = $webinar->speaker;
    $portrait = $speaker?->portraitUrl('thumb');
@endphp

<article class="wd-card">
    <div class="wd-card-media">
        @if ($image)
            <img src="{{ $image }}" alt="" loading="lazy">
        @else
            <div class="wd-card-media-none" aria-hidden="true"></div>
        @endif

        @if ($date)
            <p class="wd-card-date">
                <x-ui.icon name="calendar-days" :size="16" />
                <span class="wd-card-day">{{ $date->format('j') }}</span>
                <span class="wd-card-month">{{ $date->format('M') }}</span>
            </p>
        @endif

        <p class="wd-card-type">{{ $webinar->type->label() }}</p>

        @if ($webinar->accreditations->isNotEmpty())
            <p class="wd-card-creds">
                @foreach ($webinar->accreditations as $accreditation)
                    {{-- Each body carries its own colour so the chips read as
                         accreditation marks rather than generic tags. --}}
                    <span style="color: {{ $accreditation->brandColor() }}">{{ $accreditation->code }}</span>
                @endforeach
            </p>
        @endif
    </div>

    <div class="wd-card-body">
        <h3>
            <a class="card-link" href="{{ route('webinars.show', $webinar) }}">{{ $webinar->title }}</a>
        </h3>

        @if ($speaker)
            <div class="wd-card-speaker">
                @if ($portrait)
                    <img src="{{ $portrait }}" alt="" width="56" height="56" loading="lazy">
                @else
                    <span class="wd-card-speaker-none" aria-hidden="true">
                        <x-ui.icon name="user-round" :size="24" />
                    </span>
                @endif

                <div>
                    <p class="wd-card-speaker-label"><x-ui.icon name="user-round" :size="14" /> Speaker</p>
                    <p class="wd-card-speaker-name">
                        {{ $speaker->name }}@if ($speaker->credentials), {{ $speaker->credentials }}@endif
                    </p>
                    @if ($speaker->title)
                        <p class="wd-card-speaker-role">{{ $speaker->title }}</p>
                    @endif
                </div>
            </div>
        @endif

        {{-- The whole title is already a link; this repeats the destination for
             the pointer target the comp draws, and is hidden from the
             accessibility tree so it is not announced twice. --}}
        <a class="wd-card-cta" href="{{ route('webinars.show', $webinar) }}" tabindex="-1" aria-hidden="true">
            View Details
        </a>
    </div>
</article>
