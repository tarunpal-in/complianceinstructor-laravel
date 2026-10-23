@props(['webinar'])

@php
    $banner = $webinar->bannerImageUrl() ?? $webinar->cardImageUrl();
@endphp

<section class="wd-hero" @if ($banner) style="background-image: url('{{ $banner }}')" @endif>
    {{-- The tint is a separate element rather than a background gradient so the
         banner can be swapped by the client without anyone restating the
         overlay. The comp's text sits on a heavy navy wash; a light photo
         without it would drop the H1 below AA contrast (§7 P9). --}}
    <div class="wd-hero-tint" aria-hidden="true"></div>

    <div class="wd-hero-inner">
        <div class="wd-hero-copy">
            <p class="wd-badges">
                <span class="wd-badge">{{ $webinar->type->label() }}</span>
                @if ($webinar->industry)
                    <span class="wd-badge">{{ $webinar->industry->name }}</span>
                @endif
            </p>

            <h1>{{ $webinar->title }}</h1>

            @if ($webinar->summary)
                <p class="wd-summary">{{ $webinar->summary }}</p>
            @endif

            <dl class="wd-facts">
                <div>
                    <dt><x-ui.icon name="calendar-days" :size="20" /> Date &amp; Time</dt>
                    <dd>
                        @if ($webinar->scheduled_at)
                            {{ $webinar->scheduled_at->timezone($webinar->timezone)->format('j M Y') }}
                            | {{ $webinar->scheduled_at->timezone($webinar->timezone)->format('h:i A T') }}
                        @else
                            Available on demand
                        @endif
                    </dd>
                </div>

                <div>
                    <dt><x-ui.icon name="clock-3" :size="20" /> Duration</dt>
                    <dd>{{ $webinar->duration_minutes ? $webinar->duration_minutes.' Minutes' : 'Self-paced' }}</dd>
                </div>

                <div>
                    <dt><x-ui.icon name="user-round" :size="20" /> Speaker</dt>
                    <dd>{{ $webinar->speaker?->name ?? 'To be announced' }}</dd>
                </div>

                <div>
                    <dt><x-ui.icon name="award" :size="20" /> Credits</dt>
                    <dd>{{ $webinar->creditBodies() }}</dd>
                </div>
            </dl>

            <div class="wd-hero-actions">
                <a class="btn-primary" href="#overview">Click to Know More</a>

                {{-- Opens a popup form to email this webinar's details to someone. --}}
                <livewire:share-webinar :webinar="$webinar" />
            </div>
        </div>

        <div class="wd-panel-stack">
            {{-- One panel for everything: it shows a member their included
                 formats and the redeem action itself (§5.2 / P5). --}}
            <livewire:purchase-panel :webinar="$webinar" />
        </div>
    </div>
</section>
