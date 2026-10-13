@extends('layouts.public')


@section('content')
    {{-- Hero — design-ui/All Speakers Page.png --}}
    <section class="spk-hero">
        <div class="spk-hero-inner">
            <span class="spk-eyebrow">JOIN OUR FACULTY</span>
            <h1>Learn from the Best in Compliance</h1>
            <p>Every {{ config('app.name') }} speaker is a working practitioner — an attorney, CPA, certified HR professional, or regulatory specialist with real-world authority in their field.</p>
        </div>
    </section>

    <section class="section spk-list">
        {{-- Filters are a plain GET form: no Livewire in this app, and a shareable
             URL is the right thing for a filtered list. Selects submit on change. --}}
        <form class="spk-filters" method="get" action="{{ route('speakers.index') }}" role="search">
            <div class="spk-search">
                <x-ui.icon name="search" :size="18" />
                <label for="spk-q" class="sr-only">Search speakers</label>
                <input id="spk-q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search by name, specialty, or keyword…">
            </div>

            <label class="sr-only" for="spk-topic">Topic</label>
            <select id="spk-topic" name="topic" class="spk-select" onchange="this.form.submit()">
                <option value="">All Topics</option>
                @foreach ($topics as $topic)
                    <option value="{{ $topic->slug }}" @selected($filters['topic'] === $topic->slug)>{{ $topic->name }}</option>
                @endforeach
            </select>

            <label class="sr-only" for="spk-credit">Credits</label>
            <select id="spk-credit" name="credit" class="spk-select" onchange="this.form.submit()">
                <option value="">All Credits</option>
                @foreach ($credits as $credit)
                    <option value="{{ $credit->code }}" @selected($filters['credit'] === $credit->code)>{{ $credit->code }}</option>
                @endforeach
            </select>

            <label class="sr-only" for="spk-sort">Sort</label>
            <select id="spk-sort" name="sort" class="spk-select" onchange="this.form.submit()">
                @foreach ($sorts as $value => $label)
                    <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                @endforeach
            </select>

            {{-- Submits the typed search for keyboard users; the selects submit
                 themselves. No visible button — the comp has none. --}}
            <button type="submit" class="sr-only">Search</button>
        </form>

        <p class="spk-count">
            <strong>{{ $speakers->count() }}</strong> {{ Str::plural('instructor', $speakers->count()) }} found
        </p>

        @if ($speakers->isEmpty())
            <p class="spk-empty">No speakers match your filters yet. <a href="{{ route('speakers.index') }}">Clear filters</a></p>
        @else
            <div class="spk-grid">
                @foreach ($speakers as $speaker)
                    <x-speaker.card :speaker="$speaker" />
                @endforeach
            </div>
        @endif
    </section>

    {{-- "Join our faculty" CTA band --}}
    <section class="spk-cta-wrap section">
        <div class="spk-cta">
            <div>
                <span class="spk-eyebrow">JOIN OUR FACULTY</span>
                <h2>Are you a compliance expert?</h2>
                <p>Apply to become a {{ config('app.name') }} instructor. Share your expertise with 200,000+ compliance professionals and earn revenue from every registration.</p>
            </div>

            <div class="spk-cta-actions">
                <button class="btn-primary" @click="showNotice('Speaker applications are opening soon')"><x-ui.icon name="user-round" :size="18" /> Apply to Speak</button>
                <a class="btn-ghost" href="#">Learn More</a>
            </div>
        </div>
    </section>
@endsection
