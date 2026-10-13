<x-mail::message>
# Thank you for your order

Hello {{ $order->customerName() }},

Your payment has gone through and order **{{ $order->number }}** is confirmed. Here is what you bought:

<x-mail::table>
| Item | Qty | Amount |
|:-----|:---:|-------:|
@foreach ($order->items as $item)
| {{ $item->description }} | {{ $item->quantity }} | {{ \App\Support\Money::format($item->line_total_cents, $order->currency) }} |
@endforeach
</x-mail::table>

@if ($order->discount_cents > 0)
Discount applied: &minus;{{ \App\Support\Money::format($order->discount_cents, $order->currency) }}
@endif

**Total paid: {{ \App\Support\Money::format($order->total_cents, $order->currency) }}**

<x-mail::button :url="$invoiceUrl">
Download your invoice
</x-mail::button>

You can reach everything you bought from your account dashboard. If any seat was booked for a colleague, they will have had their own email.

@include('emails.partials.footer')
</x-mail::message>
