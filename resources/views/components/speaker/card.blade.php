@props(['speaker'])

@php
    $portrait = $speaker->portraitUrl('portrait');
    $initials = \Illuminate\Support\Str::of($speaker->name)
        ->explode(' ')
        ->map(fn (string $part): string => \Illuminate\Support\Str::substr($part, 0, 1))
        ->take(2)
        ->implode('');
    $codes = $speaker->accreditationCodes();
@endphp

<article class="spk-card">
    <div class="spk-card-photo">
        @if ($portrait)
            <img src="{{ $portrait }}" alt="{{ $speaker->name }}" loading="lazy">
        @else
            <span class="spk-card-initials" aria-hidden="true">{{ Str::upper($initials) }}</span>
        @endif
    </div>

    <div class="spk-card-body">
        <h3>{{ $speaker->name }}</h3>

        @if ($speaker->credentials || $speaker->title)
            <p class="spk-card-role">{{ collect([$speaker->credentials, $speaker->title])->filter()->join(' · ') }}</p>
        @endif

        @if ($codes !== [])
            <p class="spk-card-creds">{{ implode(' · ', $codes) }}</p>
        @endif

        <a class="spk-card-link" href="{{ route('speakers.show', $speaker) }}">
            View Profile <x-ui.icon name="arrow-right" :size="16" />
        </a>
    </div>
</article>
