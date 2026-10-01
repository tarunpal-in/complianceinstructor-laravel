<x-mail::message>
# A refund has been scheduled

**{{ $amount }}** will be refunded to the customer on order **{{ $order->number }}** via {{ $gateway }}.

- Raised by: {{ $raisedBy }}
- Reason given: {{ $reason }}

{{-- The whole reason for the mail. Saying when, not just that, is what makes it
     actionable — and the window is the only chance to stop money leaving. --}}
@if ($windowMinutes > 0)
It will be sent to the gateway in about **{{ $windowMinutes }} minutes**. Until then it can be stopped.
@else
It will be sent to the gateway **within the minute**.
@endif

<x-mail::button :url="$reviewUrl">
Review this payment
</x-mail::button>

{{-- Goes to every Super Admin including whoever raised it, because the case this
     guards against is an account being used by somebody other than its owner. --}}
If you did not expect this, open it and cancel it now.

@include('emails.partials.footer')
</x-mail::message>
