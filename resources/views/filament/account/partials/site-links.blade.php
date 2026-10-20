{{-- A clear way back to the public site from the learner panel. --}}
<div class="ld-site-links">
    <a href="{{ route('webinars.index') }}" class="ld-site-link primary">
        <x-filament::icon icon="heroicon-o-magnifying-glass" />
        Browse all webinars
    </a>
    <a href="{{ route('home') }}" class="ld-site-link ghost">
        <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" />
        Back to website
    </a>
</div>
