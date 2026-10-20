<x-mail::message>
# A webinar you might find useful

{{ $sharerName }} ({{ $sharerEmail }}) thought you'd be interested in this session and asked us to send you the details.

@if (filled($note))
> {{ $note }}
@endif

## {{ $webinar->title }}

@if ($dateLabel)
**When:** {{ $dateLabel }}@if ($timeLabel) · {{ $timeLabel }}@endif
@endif
@if ($webinar->duration_minutes)
**Duration:** {{ $webinar->duration_minutes }} minutes
@endif
@if ($webinar->speaker)
**Speaker:** {{ $webinar->speaker->name }}{{ $webinar->speaker->title ? ' — '.$webinar->speaker->title : '' }}
@endif
**Credits:** {{ $creditBodies }}

<x-mail::button :url="$url">
View the webinar
</x-mail::button>

If this isn't something you asked for, you can safely ignore this email — you have not been added to any list.

@include('emails.partials.footer')
</x-mail::message>
