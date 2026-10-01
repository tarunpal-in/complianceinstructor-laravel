<x-mail::message>
# One click and you are on the list

@if (filled($subscriber->name))
Hello {{ $subscriber->name }},
@else
Hello,
@endif

Somebody signed **{{ $subscriber->email }}** up to hear about new webinars and continuing-education credits from {{ \App\Models\Setting::declared('business.name') }}.

Confirm that it was you and we will add you to the list.

<x-mail::button :url="$confirmUrl">
Yes, add me to the list
</x-mail::button>

{{-- No unsubscribe link here, and the footer will not add one: they are not on
     the list yet, so an opt-out would lead nowhere. Doing nothing is the opt-out. --}}
If it was not you, do nothing. We will not add you, and you will not hear from us again.

@include('emails.partials.footer')
</x-mail::message>
