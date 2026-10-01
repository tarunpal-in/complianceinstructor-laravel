<x-mail::message>
# Reset your password

@if (filled($user->name))
Hello {{ $user->name }},
@else
Hello,
@endif

Somebody asked to reset the password for the account registered to **{{ $user->email }}**.

<x-mail::button :url="$resetUrl">
Choose a new password
</x-mail::button>

This link expires in {{ $expiresInMinutes }} minutes and can only be used once.

{{-- Said plainly, because the reassurance is the point: an unexpected reset
     email is only alarming if it implies something has already happened. --}}
If you did not ask for this, you can ignore this email. Your password has not been changed.

@include('emails.partials.footer')
</x-mail::message>
