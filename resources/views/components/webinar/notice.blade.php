@props(['tone' => 'amber', 'heading' => null, 'text'])

{{-- Not role="alert": these are standing page content, and an alert role would
     have a screen reader interrupt to announce them on load. --}}
<aside class="wd-notice wd-notice-{{ $tone }}">
    @if ($heading)
        <h3><x-ui.icon name="info" :size="18" /> {{ $heading }}</h3>

        @foreach (preg_split('/\R+/', trim($text)) as $line)
            @if (trim($line) !== '')
                <p>{{ trim($line) }}</p>
            @endif
        @endforeach
    @else
        <p><x-ui.icon name="info" :size="18" /> {{ $text }}</p>
    @endif
</aside>
