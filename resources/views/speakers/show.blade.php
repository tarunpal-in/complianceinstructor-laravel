@extends('layouts.public')


@section('content')
    @php
        $portrait = $speaker->getFirstMediaUrl('portrait', 'portrait') ?: $speaker->getFirstMediaUrl('portrait');
        $initials = \Illuminate\Support\Str::of($speaker->name)->explode(' ')
            ->map(fn (string $p): string => \Illuminate\Support\Str::substr($p, 0, 1))->take(2)->implode('');
        $codes = $speaker->accreditationCodes();
        $live = $speaker->webinarsOfType(\App\Enums\WebinarType::Live);
        $recorded = $speaker->webinarsOfType(\App\Enums\WebinarType::Recorded);
        $topics = $speaker->topicDomains();
        $credits = $speaker->accreditationsOffered();
        $enrolled = $speaker->totalEnrolled();
        $expertise = array_values(array_filter((array) ($speaker->expertise ?? [])));
        $firstName = \Illuminate\Support\Str::before($speaker->name, ' ') ?: $speaker->name;
    @endphp

    <div class="spk-crumbs">@include('partials.breadcrumbs')</div>

    {{-- Hero — design-ui/Single Speaker Page.png --}}
    <section class="sp-hero">
        <div class="sp-hero-inner">
            <div class="sp-hero-photo">
                @if ($portrait)
                    <img src="{{ $portrait }}" alt="{{ $speaker->name }}">
                @else
                    <span aria-hidden="true">{{ Str::upper($initials) }}</span>
                @endif
            </div>

            <div class="sp-hero-copy">
                <span class="sp-eyebrow">SPEAKER</span>
                <h1>{{ $speaker->name }}</h1>

                @if ($speaker->credentials || $speaker->title)
                    <p class="sp-role">{{ collect([$speaker->credentials, $speaker->title])->filter()->join(' · ') }}</p>
                @endif

                @if ($codes !== [])
                    <p class="sp-hero-badges">@foreach ($codes as $code)<b>{{ $code }}</b>@endforeach</p>
                @endif

                @if ($speaker->linkedin_url)
                    <p class="sp-social">
                        <a href="{{ $speaker->linkedin_url }}" target="_blank" rel="noopener" aria-label="LinkedIn">
                            <x-ui.icon name="linkedin" :size="18" />
                        </a>
                    </p>
                @endif
            </div>
        </div>
    </section>

    <section class="section sp-body">
        <div class="sp-layout">
            <div class="sp-main">
                {{-- About --}}
                <div class="sp-card">
                    <h2><x-ui.icon name="user-round" :size="20" /> About {{ $firstName }}</h2>

                    @foreach (preg_split('/\R{2,}/', trim((string) $speaker->bio)) as $paragraph)
                        @if (trim($paragraph) !== '')<p class="sp-about-text">{{ trim($paragraph) }}</p>@endif
                    @endforeach

                    @if ($expertise !== [])
                        <h3 class="sp-subhead">Areas of expertise</h3>
                        <div class="sp-tags">
                            @foreach ($expertise as $area)
                                <span class="sp-tag"><x-ui.icon name="check" :size="14" /> {{ $area }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if ($topics->isNotEmpty())
                        <h3 class="sp-subhead">Topic domains</h3>
                        <div class="sp-tags">
                            @foreach ($topics as $topic)
                                <span class="sp-domain">{{ $topic->name }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Webinars with Live / Recorded tabs --}}
                @if ($live->isNotEmpty() || $recorded->isNotEmpty())
                    <div class="sp-card" x-data="{ tab: '{{ $live->isNotEmpty() ? 'live' : 'recorded' }}' }">
                        <h2><x-ui.icon name="monitor" :size="20" /> Webinars by {{ $speaker->name }}</h2>

                        <div class="sp-tabs" role="tablist">
                            <button type="button" class="sp-tab" :class="{ 'is-active': tab === 'live' }" @click="tab = 'live'">
                                <x-ui.icon name="monitor" :size="16" /> Live Webinars <span class="sp-tab-count">{{ $live->count() }}</span>
                            </button>
                            <button type="button" class="sp-tab" :class="{ 'is-active': tab === 'recorded' }" @click="tab = 'recorded'">
                                <x-ui.icon name="monitor" :size="16" /> Recorded <span class="sp-tab-count">{{ $recorded->count() }}</span>
                            </button>
                        </div>

                        <div x-show="tab === 'live'">
                            @if ($live->isEmpty())
                                <p class="sp-none">No live sessions scheduled right now.</p>
                            @else
                                <p class="sp-avail"><span class="sp-avail-pill live">Live Webinars</span> {{ $live->count() }} available</p>
                                @foreach ($live as $webinar)
                                    <x-speaker.webinar-row :webinar="$webinar" />
                                @endforeach
                            @endif
                        </div>

                        <div x-show="tab === 'recorded'" x-cloak>
                            @if ($recorded->isEmpty())
                                <p class="sp-none">No recordings yet.</p>
                            @else
                                <p class="sp-avail"><span class="sp-avail-pill">Recorded</span> {{ $recorded->count() }} available</p>
                                @foreach ($recorded as $webinar)
                                    <x-speaker.webinar-row :webinar="$webinar" />
                                @endforeach
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <aside class="sp-side">
                {{-- Instructor stats — rows render only when the figure exists. --}}
                <div class="sp-stats">
                    <div class="sp-stats-head">
                        <small>INSTRUCTOR STATS</small>
                        <strong>{{ $speaker->name }}</strong>
                    </div>

                    <dl>
                        @if ($speaker->rating !== null)
                            <div><dt>Overall Rating</dt><dd class="sp-rating">{{ rtrim(rtrim((string) $speaker->rating, '0'), '.') }} / 5</dd></div>
                        @endif
                        @if ($speaker->reviews_count !== null)
                            <div><dt>Total Reviews</dt><dd>{{ number_format($speaker->reviews_count) }}</dd></div>
                        @endif
                        @if ($speaker->years_teaching !== null)
                            <div><dt>Years Teaching</dt><dd>{{ $speaker->years_teaching }} {{ Str::plural('year', $speaker->years_teaching) }}</dd></div>
                        @endif
                        <div><dt>Live Webinars</dt><dd class="sp-count-live">{{ $live->count() }}</dd></div>
                        <div><dt>Recorded Webinars</dt><dd class="sp-count-live">{{ $recorded->count() }}</dd></div>
                        @if ($enrolled > 0)
                            <div><dt>Total Enrolled</dt><dd>{{ number_format($enrolled) }}</dd></div>
                        @endif
                    </dl>
                </div>

                {{-- Accredited credits offered --}}
                @if ($credits->isNotEmpty())
                    <div class="sp-credits">
                        <small>ACCREDITED CREDITS OFFERED</small>
                        @foreach ($credits as $credit)
                            <div class="sp-credit">
                                <span class="sp-credit-icon"><x-ui.icon name="award" :size="18" /></span>
                                <div>
                                    <strong>{{ $credit->code }}</strong>
                                    <span>{{ $credit->name }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </aside>
        </div>
    </section>
@endsection
