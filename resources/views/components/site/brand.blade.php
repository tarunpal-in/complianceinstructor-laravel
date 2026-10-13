{{-- The comp's hexagonal mark: a navy/gold split rendered with clip-path, with
     the wordmark stacked COMPLIANCE over INSTRUCTOR at 5px tracking. --}}
<a class="brand" href="{{ route('home') }}" aria-label="{{ config('app.name') }} home">
    <span class="brand-mark"><x-ui.icon name="shield-check" :size="23" /></span>
    <span>COMPLIANCE<small>INSTRUCTOR</small></span>
</a>
