<x-mail::message>
# Confirm your email address

Hello {{ $user->name }},

Thanks for creating a {{ config('app.name') }} account. Please confirm this is your email address so we can keep your memberships, invoices and certificates tied to an address you own.

<x-mail::button :url="$verifyUrl">
Verify email address
</x-mail::button>

If you did not create an account, you can ignore this email and nothing further will happen.

@include('emails.partials.footer')
</x-mail::message>
