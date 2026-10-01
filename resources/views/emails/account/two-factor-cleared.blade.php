<x-mail::message>
# Your two-factor authentication was reset

@if (filled($user->name))
Hello {{ $user->name }},
@else
Hello,
@endif

@if (filled($clearedByName))
**{{ $clearedByName }}** cleared the two-factor setup on your account.
@else
An administrator cleared the two-factor setup on your account.
@endif

This is normally done when somebody has lost the phone holding their authenticator app.

**Until you set it up again, your account is protected by your password alone.** You can set it up from your profile the next time you sign in.

If you did not ask for this, contact us straight away.

@include('emails.partials.footer')
</x-mail::message>
