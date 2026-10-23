<x-mail::message>
# One click to unsubscribe

@if (filled($subscriber->name))
Hello {{ $subscriber->name }},
@else
Hello,
@endif

Somebody asked to unsubscribe **{{ $subscriber->email }}** from {{ \App\Models\Setting::declared('business.name') }} emails.

Use the button below to confirm, and we will take this address off the list.

<x-mail::button :url="$unsubscribeUrl">
Unsubscribe me
</x-mail::button>

{{-- The request came from a public form, so the actual removal is behind this
     signed link and one more click — the same reason joining the list is
     confirmed from the inbox. --}}
If it was not you, do nothing and you will stay subscribed.

@include('emails.partials.footer')
</x-mail::message>
