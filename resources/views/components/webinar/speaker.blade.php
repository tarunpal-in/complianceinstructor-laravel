@props(['speaker', 'id' => 'speakers'])

<section class="wd-section" id="{{ $id }}" tabindex="-1">
    <h2>Speaker(s)</h2>

    @if ($speaker)
        <article class="wd-speaker">
            <div class="wd-speaker-aside">
                @php $portrait = $speaker->portraitUrl('thumb'); @endphp

                @if ($portrait)
                    <img src="{{ $portrait }}" alt="{{ $speaker->name }}" width="254" height="254" loading="lazy">
                @else
                    <div class="wd-speaker-portrait-none" aria-hidden="true">
                        <x-ui.icon name="user-round" :size="48" />
                    </div>
                @endif

                <h3>{{ $speaker->name }}</h3>

                @if ($speaker->title)
                    <p class="wd-speaker-role">{{ $speaker->title }}</p>
                @endif

                @if ($speaker->credentials)
                    <p class="wd-speaker-creds">{{ $speaker->credentials }}</p>
                @endif
            </div>

            <div class="wd-speaker-body">
                <p class="wd-speaker-rating">
                    <span class="wd-speaker-stars" aria-hidden="true">
                        @for ($i = 0; $i < 5; $i++)
                            <x-ui.icon name="star" :size="16" />
                        @endfor
                    </span>
                    Top-Rated {{ config('app.name') }} Instructor
                </p>

                @foreach (preg_split('/\R{2,}/', trim((string) $speaker->bio)) as $paragraph)
                    @if (trim($paragraph) !== '')
                        <p>{{ trim($paragraph) }}</p>
                    @endif
                @endforeach
            </div>
        </article>
    @else
        <p class="wd-empty">The speaker for this session has not been announced yet.</p>
    @endif
</section>
