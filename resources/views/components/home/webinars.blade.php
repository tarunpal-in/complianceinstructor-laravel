@props(['webinars' => []])

<section class="webinars section" id="training">
    <div class="section-heading split">
        <div>
            <div class="eyebrow">LIVE EVENTS</div>
            <h2>Upcoming Webinars</h2>
            <p>Expert-led sessions with recognized CEU credits. Register today.</p>
        </div>
        <a class="text-link" href="#webinars">All Upcoming Webinars <x-ui.icon name="arrow-right" :size="16" /></a>
    </div>

    <div class="webinar-grid" id="webinars">
        @foreach ($webinars as $webinar)
            @php $starts = $webinar['starts_at']; @endphp

            <article class="webinar-card">
                <div class="webinar-photo">
                    <img src="{{ asset('images/home/workshop.jpg') }}" alt="Audience at a webinar" width="940" height="628">

                    {{-- Badge and body date derive from one Carbon instance. The
                         comp badges "26 AUG" on a card whose body reads "Aug 22";
                         two fields drifting is exactly what this prevents. --}}
                    <div class="date-badge">
                        <x-ui.icon name="calendar-days" :size="14" />
                        <strong>{{ $starts->format('d') }}</strong>
                        <span>{{ Str::upper($starts->format('M')) }}</span>
                    </div>

                    <span class="live-badge">● LIVE WEBINAR</span>

                    <div class="photo-tags">
                        @foreach ($webinar['accreditations'] as $code)<b>{{ $code }}</b>@endforeach
                    </div>
                </div>

                <div class="webinar-body">
                    <h3>{{ $webinar['title'] }}</h3>

                    <p>
                        <x-ui.icon name="calendar-days" :size="18" />
                        <time datetime="{{ $starts->toIso8601String() }}">{{ $starts->format('M j, Y') }} · {{ $webinar['time_label'] }}</time>
                    </p>

                    <p><x-ui.icon name="clock-3" :size="18" /> {{ $webinar['duration'] }} <em>◉ &nbsp;LIVE WEBINAR</em></p>

                    <div class="speaker">
                        <div class="speaker-avatar">{{ $webinar['speaker_initials'] }}</div>
                        <div>
                            <b><x-ui.icon name="user-round" :size="15" /> Speaker</b>
                            <strong>{{ $webinar['speaker'] }}</strong>
                            <small>{{ $webinar['speaker_title'] }}</small>
                        </div>
                    </div>

                    <button @click="showNotice('Webinar details opened')">View Details</button>
                </div>
            </article>
        @endforeach
    </div>
</section>
