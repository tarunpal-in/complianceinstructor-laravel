<x-mail::message>
# We could not take your payment

Hello {{ $order->customerName() }},

We tried to take **{{ $amount }}** for order **{{ $order->number }}**, but the payment was declined by your bank or card provider. Nothing has been charged, and the order is not confirmed.

Your items are still in your cart, so you can try again — a different card often clears it straight away.

<x-mail::button :url="$cartUrl">
Return to your cart
</x-mail::button>

If it keeps failing, reply to this email with your order number and we will help.

@include('emails.partials.footer')
</x-mail::message>
