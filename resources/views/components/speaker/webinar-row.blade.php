@props(['webinar'])

@php
    $image = $webinar->cardImageUrl();
    $date = $webinar->scheduled_at?->timezone($webinar->timezone);
    $codes = $webinar->accreditations->pluck('code');
@endphp

<div class="sp-webinar">
    <div class="sp-webinar-thumb">
        @if ($image)
            <img src="{{ $image }}" alt="" loading="lazy">
        @endif
    </div>

    <div class="sp-webinar-main">
        <a class="sp-webinar-title" href="{{ route('webinars.show', $webinar) }}">{{ $webinar->title }}</a>

        <p class="sp-webinar-meta">
            <span><x-ui.icon name="calendar-days" :size="15" /> {{ $date?->format('j M Y') ?? 'On demand' }}</span>
            <span><x-ui.icon name="clock-3" :size="15" /> {{ $webinar->duration_minutes ? $webinar->duration_minutes.' min' : 'Self-paced' }}</span>
        </p>

        @if ($codes->isNotEmpty())
            <p class="sp-webinar-creds">@foreach ($codes as $code)<b>{{ $code }}</b>@endforeach</p>
        @endif
    </div>

    <a class="sp-webinar-btn" href="{{ route('webinars.show', $webinar) }}">View details</a>
</div>
