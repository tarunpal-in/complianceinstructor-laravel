@props(['webinar'])

@php
    use App\Enums\WebinarType;

    $starts = $webinar->scheduled_at;
    $speaker = $webinar->speaker;
    $portrait = $speaker?->portraitUrl('thumb');
    $isLive = $webinar->type === WebinarType::Live;
    $image = $webinar->cardImageUrl() ?? asset('images/home/workshop.jpg');
    $url = route('webinars.show', $webinar);

    $speakerName = $speaker
        ? $speaker->name.($speaker->credentials ? ', '.$speaker->credentials : '')
        : null;
    $initials = $speaker
        ? \Illuminate\Support\Str::of($speaker->name)->explode(' ')->filter()->take(2)->map(fn ($p) => \Illuminate\Support\Str::substr($p, 0, 1))->implode('')
        : '';
@endphp

<article class="webinar-card">
    <div class="webinar-photo">
        <img src="{{ $image }}" alt="{{ $webinar->title }}" width="940" height="628" loading="lazy" decoding="async">

        @if ($starts)
            <div class="date-badge">
                <x-ui.icon name="calendar-days" :size="16" />
                <strong>{{ $starts->format('d') }}</strong>
                <span>{{ \Illuminate\Support\Str::upper($starts->format('M')) }}</span>
            </div>
        @endif

        <span class="live-badge">● {{ $isLive ? 'LIVE WEBINAR' : 'RECORDED' }}</span>

        @if ($webinar->accreditations->isNotEmpty())
            <div class="photo-tags">
                @foreach ($webinar->accreditations as $accreditation)<b>{{ $accreditation->code }}</b>@endforeach
            </div>
        @endif
    </div>

    <div class="webinar-body">
        <h3><a class="card-link" href="{{ $url }}">{{ $webinar->title }}</a></h3>

        <div class="webinar-meta">
            <p>
                <x-ui.icon name="calendar-days" :size="24" />
                @if ($starts)
                    <time datetime="{{ $starts->toIso8601String() }}">{{ $webinar->scheduledDateLabel() }} · {{ $webinar->scheduledTimeLabel() }}</time>
                @else
                    <time>Available on demand</time>
                @endif
            </p>

            @if ($webinar->duration_minutes)
                <p>
                    <x-ui.icon name="clock-3" :size="24" /> {{ $webinar->duration_minutes }} Min
                    <em>◉ &nbsp;{{ $isLive ? 'LIVE WEBINAR' : 'RECORDED' }}</em>
                </p>
            @endif
        </div>

        @if ($speaker)
            <div class="speaker">
                @if ($portrait)
                    <div class="speaker-avatar" style="background-image: url('{{ $portrait }}'); background-size: cover; background-position: center;"></div>
                @else
                    <div class="speaker-avatar">{{ $initials }}</div>
                @endif
                <div>
                    <b><x-ui.icon name="user-round" :size="15" /> Speaker</b>
                    <strong>{{ $speakerName }}</strong>
                    <small>{{ $speaker->title }}</small>
                </div>
            </div>
        @endif

        <a class="cat-view" href="{{ $url }}">View Details</a>
    </div>
</article>
