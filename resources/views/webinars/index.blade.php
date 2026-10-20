@extends('layouts.public')

@section('content')
    <section class="cat-hero">
        <div class="cat-hero-inner">
            <h1>{{ $heading }}</h1>
            @if (! empty($subheading))
                <p>{{ $subheading }}</p>
            @endif
        </div>
    </section>

    <section class="cat-section">
        <livewire:webinar-catalogue :industry-slug="$industrySlug" />
    </section>
@endsection
