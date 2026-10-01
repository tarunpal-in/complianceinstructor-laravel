<x-mail::message>
# You have been given access

@if (filled($user->name))
Hello {{ $user->name }},
@else
Hello,
@endif

{{-- Who sent it is the line that turns this from a suspicious link into an
     expected one. The invitation used to be a bare password-reset email, which
     reads as phishing — and deleting it is the sensible reaction to that. --}}
@if (filled($invitedByName))
**{{ $invitedByName }}** has given you access to the {{ config('app.name') }} admin.
@else
You have been given access to the {{ config('app.name') }} admin.
@endif

@if (count($roles) > 0)
Your role{{ count($roles) === 1 ? '' : 's' }}: **{{ implode(', ', $roles) }}**.
@endif

Set your password using the link below, and you are in.

<x-mail::button :url="$resetUrl">
Set your password
</x-mail::button>

The link expires in {{ $expiresInMinutes }} minutes and works once. If it has expired, ask whoever invited you to send it again.

{{-- Stated because it is a real policy and §9's first critical finding was an
     endpoint that broke it. Somebody who knows nobody can set their password
     for them knows to be suspicious of anyone offering to. --}}
Nobody here can see or set your password — not even an administrator.

@include('emails.partials.footer')
</x-mail::message>
