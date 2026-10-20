<x-filament-panels::page>
    @php
        $items = $this->items();
        $format = $this->learningFormat();
        $opensInNewTab = method_exists($this, 'opensInNewTab') && $this->opensInNewTab();
    @endphp

    @forelse ($items as $row)
        @php
            $webinar = $row['webinar'];
            $asset = $row['asset'];
            $handouts = $row['handouts'];
            $ready = $asset && $asset->isReleased();
        @endphp

        <div class="ld-card">
            <div class="ld-card-top">
                <div class="ld-card-main">
                    <h3 class="ld-card-title">{{ $webinar->title }}</h3>
                    @if ($webinar->speaker)
                        <p class="ld-card-speaker">with {{ $webinar->speaker->name }}</p>
                    @endif
                    @if ($webinar->scheduled_at)
                        <p class="ld-card-date">{{ $webinar->scheduled_at->format('l j F Y, g:ia') }}</p>
                    @endif
                </div>

                <div class="ld-card-action">
                    @if ($ready)
                        @php
                            // Play inside the site only when the admin marked the
                            // asset embeddable (an R2 upload or a platform embed
                            // URL); otherwise open it directly — a share link or a
                            // signed download — in a new tab so the dashboard stays.
                            $usePlayer = $asset->isPlayable();
                            $href = $usePlayer
                                ? route('learning.watch', [$webinar->slug, $format->value])
                                : route('learning.access', [$webinar->slug, $format->value]);
                            $newTab = $opensInNewTab || ! $usePlayer;
                        @endphp
                        <a href="{{ $href }}"
                           @if ($newTab) target="_blank" rel="noopener" @endif
                           class="ld-btn">
                            {{ $this->actionLabel() }}
                        </a>
                    @else
                        <span class="ld-pending">
                            @if ($asset && $asset->available_from)
                                Available {{ $asset->available_from->format('j M Y') }}
                            @else
                                Not available yet
                            @endif
                        </span>
                    @endif
                </div>
            </div>

            @if ($handouts->isNotEmpty())
                <div class="ld-handouts">
                    <span class="ld-handouts-label">Study materials</span>
                    <ul class="ld-handouts-list">
                        @foreach ($handouts as $handout)
                            <li>
                                <a href="{{ route('learning.handout', $handout) }}" class="ld-handout" target="_blank" rel="noopener">
                                    <span class="ld-handout-ext">{{ $handout->extension() }}</span>
                                    <span class="ld-handout-name">{{ $handout->displayName() }}</span>
                                    <span class="ld-handout-get">Download</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @empty
        <div class="ld-empty">
            <span class="ld-empty-icon">
                <x-filament::icon icon="heroicon-o-inbox" class="h-7 w-7" />
            </span>
            <p class="ld-empty-text">{{ $this->emptyText() }}</p>
            <a href="{{ route('webinars.index') }}" class="ld-empty-link">Browse webinars</a>
        </div>
    @endforelse
</x-filament-panels::page>
