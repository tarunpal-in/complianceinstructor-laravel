<x-mail::message>
# Your password was changed

@if (filled($user->name))
Hello {{ $user->name }},
@else
Hello,
@endif

The password for **{{ $user->email }}** was changed on {{ $changedAt->format('j F Y') }} at {{ $changedAt->format('H:i') }}.

If that was you, there is nothing to do.

{{-- No link, deliberately. An unexpected security email whose advice is "click
     here" trains people into the habit that loses accounts. Telling them to come
     to us is slower and safer. --}}
**If it was not you, contact us straight away** — reply to this email, and do not use any link sent to you about it.

@include('emails.partials.footer')
</x-mail::message>
