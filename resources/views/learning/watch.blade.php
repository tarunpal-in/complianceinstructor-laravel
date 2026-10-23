@extends('layouts.public')

@section('content')
    <section class="section watch-page">
        <a class="watch-back" href="{{ route('filament.account.pages.recorded-videos') }}">
            <span aria-hidden="true">&larr;</span> Back to my learning
        </a>

        <div class="watch-head">
            <h1>{{ $webinar->title }}</h1>
            @if ($webinar->speaker)
                <p>with {{ $webinar->speaker->name }} · {{ $format->label() }}</p>
            @endif
        </div>

        <div class="watch-player">
            @if ($isFile)
                {{-- A file we hold, streamed from a signed, expiring URL. --}}
                <video controls controlsList="nodownload" preload="metadata" src="{{ $src }}"></video>
            @else
                {{-- An externally hosted recording (its platform's embed URL). --}}
                <iframe src="{{ $src }}"
                        title="{{ $webinar->title }}"
                        loading="lazy"
                        allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen></iframe>
            @endif
        </div>

        @if ($isFile)
            {{-- Report playback progress so a watch-through certificate issues the
                 moment the rule is met (§8). Only for a file we serve: an external
                 iframe is cross-origin and its playback cannot be observed. --}}
            <script>
                (function () {
                    var video = document.querySelector('.watch-player video');
                    if (! video) { return; }

                    var endpoint = @json(route('learning.watch.progress', $webinar));
                    var token = @json(csrf_token());
                    var lastSentAt = 0;
                    var lastPosition = 0;

                    function report(force) {
                        var position = Math.floor(video.currentTime || 0);

                        // At most once every 30s while playing; always on finish.
                        if (! force && (Date.now() - lastSentAt < 30000 || position <= lastPosition)) {
                            return;
                        }

                        var watched = Math.max(0, position - lastPosition);
                        lastSentAt = Date.now();
                        lastPosition = position;

                        fetch(endpoint, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': token,
                            },
                            body: JSON.stringify({ position: position, watched: watched }),
                            keepalive: true,
                        }).catch(function () {});
                    }

                    video.addEventListener('timeupdate', function () { report(false); });
                    video.addEventListener('ended', function () { report(true); });
                    window.addEventListener('pagehide', function () { report(true); });
                })();
            </script>
        @endif

        @if ($handouts->isNotEmpty())
            <div class="watch-materials">
                <h2>Study materials</h2>
                <ul class="watch-materials-list">
                    @foreach ($handouts as $handout)
                        <li>
                            <a href="{{ route('learning.handout', $handout) }}" target="_blank" rel="noopener">
                                <span class="watch-material-ext">{{ $handout->extension() }}</span>
                                <span class="watch-material-name">{{ $handout->displayName() }}</span>
                                <span class="watch-material-get">Download</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
@endsection
