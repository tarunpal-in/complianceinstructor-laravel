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
