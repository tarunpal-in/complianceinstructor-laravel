<x-mail::message>
# A seat has been booked for you

Hello {{ $attendee->name }},

{{ $purchaserName }} has booked a seat for you at **{{ $webinarTitle }}** (order {{ $order->number }}).

When the session is ready, you will be able to join it and — where the webinar awards continuing-education credit — receive your certificate against your name. You do not need to pay anything; the seat is already yours.

If you were not expecting this, you can safely ignore this email.

@include('emails.partials.footer')
</x-mail::message>
