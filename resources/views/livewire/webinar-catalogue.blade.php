@php
    // Best-effort icon per topic from the inline icon set; a topic with no match
    // falls back to a neutral mark rather than nothing.
    $topicIcon = function (string $name): string {
        $name = \Illuminate\Support\Str::lower($name);
        return match (true) {
            str_contains($name, 'human'), str_contains($name, 'hr') => 'users-round',
            str_contains($name, 'compliance'), str_contains($name, 'ethic'), str_contains($name, 'law'), str_contains($name, 'employ') => 'shield-check',
            str_contains($name, 'leader') => 'zap',
            str_contains($name, 'food'), str_contains($name, 'safety') => 'check',
            str_contains($name, 'health') => 'star',
            str_contains($name, 'bank'), str_contains($name, 'finance'), str_contains($name, 'payroll'), str_contains($name, 'tax') => 'monitor',
            default => 'award',
        };
    };
@endphp

<div class="cat-wrap">
    {{-- Filters --}}
    <aside class="cat-filters">
        <div class="cat-filters-head">
            <x-ui.icon name="search" :size="16" /> Filters
        </div>

        {{-- Type --}}
        <div class="cat-group">
            <h4>Type</h4>
            <label class="cat-opt"><input type="radio" wire:model.live="type" value="all"> All Webinar</label>
            <label class="cat-opt"><input type="radio" wire:model.live="type" value="live"> Live Webinar</label>
            <label class="cat-opt"><input type="radio" wire:model.live="type" value="recorded"> Recorded</label>
        </div>

        {{-- Topic (industries) --}}
        <div class="cat-group">
            <h4>Topic</h4>
            @foreach ($industries as $industry)
                <label class="cat-opt cat-opt-count">
                    <span class="cat-opt-main">
                        <input type="checkbox" wire:model.live="topics" value="{{ $industry->slug }}">
                        <x-ui.icon :name="$topicIcon($industry->name)" :size="16" />
                        {{ $industry->name }}
                    </span>
                    <span class="cat-count">{{ $industry->webinars_count }}</span>
                </label>
            @endforeach
        </div>

        {{-- Credit type --}}
        <div class="cat-group">
            <h4>Credit Type</h4>
            @foreach ($accreditations as $accreditation)
                <label class="cat-opt"><input type="checkbox" wire:model.live="credits" value="{{ $accreditation->code }}"> {{ $accreditation->code }}</label>
            @endforeach
        </div>

        {{-- Duration --}}
        <div class="cat-group">
            <h4>Duration</h4>
            <label class="cat-opt"><input type="radio" wire:model.live="duration" value="any"> Any length</label>
            <label class="cat-opt"><input type="radio" wire:model.live="duration" value="under60"> Under 60 min</label>
            <label class="cat-opt"><input type="radio" wire:model.live="duration" value="60to90"> 60 – 90 min</label>
            <label class="cat-opt"><input type="radio" wire:model.live="duration" value="over90"> Over 90 min</label>
        </div>

        {{-- Max price --}}
        <div class="cat-group cat-price">
            <div class="cat-price-head">
                <h4>Max Price</h4>
                <span>{{ $maxPrice ? '$'.$maxPrice : 'Any' }}</span>
            </div>
            <input type="range" min="50" max="500" step="10" wire:model.live="maxPrice">
            <div class="cat-price-scale"><span>$50</span><span>$500+</span></div>
        </div>
    </aside>

    {{-- Results --}}
    <div class="cat-results">
        <div class="cat-results-head">
            <p><strong>{{ $total }}</strong> {{ \Illuminate\Support\Str::plural('webinar', $total) }} found</p>
            <label class="cat-sort">Sort:
                <select wire:model.live="sort">
                    <option value="popular">Most Popular</option>
                    <option value="soonest">Starting Soonest</option>
                    <option value="newest">Newest</option>
                </select>
            </label>
        </div>

        @if ($webinars->isEmpty())
            <div class="cat-empty">
                <p>No webinars match these filters.</p>
                <button type="button" wire:click="clearFilters">Clear all filters</button>
            </div>
        @else
            <div class="webinar-grid cat-grid">
                @foreach ($webinars as $webinar)
                    <x-webinar.catalogue-card :webinar="$webinar" wire:key="webinar-{{ $webinar->id }}" />
                @endforeach
            </div>
        @endif
    </div>
</div>
