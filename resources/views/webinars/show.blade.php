@extends('layouts.public')


@section('content')
    @include('partials.breadcrumbs')
    <x-webinar.breadcrumb :webinar="$webinar" />

    <x-webinar.hero :webinar="$webinar" />

    <x-webinar.tabs :sections="$sections = $webinar->contentSections()" />

    <div class="wd-layout">
        <div class="wd-main">
            @foreach ($sections as $section)
                <x-webinar.section
                    :id="$section['id']"
                    :heading="$section['heading']"
                    :body="$section['body']"
                />
            @endforeach

            <x-webinar.speaker :speaker="$webinar->speaker" id="speakers" />

            <x-webinar.notice tone="amber" :text="$qaNote" />

            @if ($instructions)
                <x-webinar.notice
                    tone="blue"
                    heading="Important Notice for Our Live Attendees"
                    :text="$instructions"
                />
            @endif
        </div>

        <aside class="wd-rail" aria-labelledby="similar-webinars">
            <h2 class="wd-rail-title" id="similar-webinars">Similar Webinars</h2>

            @forelse ($similar as $item)
                <x-webinar.rail-card :webinar="$item" />
            @empty
                <p class="wd-rail-empty">More sessions in this industry are on the way.</p>
            @endforelse
        </aside>
    </div>
@endsection
