<x-mail::message>
# Your refund has been sent

Hello {{ $order->customerName() }},

We have refunded **{{ $amount }}** against order **{{ $order->number }}**.

The money goes back to the {{ $gateway }} payment you originally made, so it should appear on the same card or account.

{{-- The question a customer actually has. Nobody asks what a refund is; they ask
     when it will show up, and a credit on a statement with a merchant descriptor
     and no explanation is what generates a support ticket or a chargeback. --}}
Depending on your bank this usually takes a few working days, and up to {{ $settlesInDays }}.

@if (filled($refund->reason))
Reason recorded: {{ $refund->reason }}
@endif

If it has not arrived after {{ $settlesInDays }} days, reply to this email with your order number and we will chase it.

@include('emails.partials.footer')
</x-mail::message>
