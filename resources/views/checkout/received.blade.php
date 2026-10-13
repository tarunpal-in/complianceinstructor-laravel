@extends('layouts.public')

@php use App\Support\Money; @endphp

@section('content')
    <section class="section checkout-received">
        <div class="cr-card">
            <span class="cr-tick"><x-ui.icon name="check" :size="30" /></span>
            <h1>Order received</h1>
            <p class="cr-lead">Thank you, {{ $order->customerName() }}. Your order <strong>{{ $order->number }}</strong> has been placed.</p>
            <p class="cr-status">Payment status: <b>{{ $order->derivedStatus()->label() }}</b></p>

            <ul class="cr-lines">
                @foreach ($order->items as $item)
                    <li>
                        <span>{{ $item->description }} × {{ $item->quantity }}</span>
                        <span>{{ Money::format($item->line_total_cents, $order->currency) }}</span>
                    </li>
                @endforeach
            </ul>

            <dl class="cr-totals">
                <div><dt>Subtotal</dt><dd>{{ Money::format($order->subtotal_cents, $order->currency) }}</dd></div>
                @if ($order->discount_cents > 0)
                    <div><dt>Discount</dt><dd>−{{ Money::format($order->discount_cents, $order->currency) }}</dd></div>
                @endif
                <div class="cr-total"><dt>Total</dt><dd>{{ Money::format($order->total_cents, $order->currency) }}</dd></div>
            </dl>

            @if ($order->capturedCents() > 0)
                <p class="cr-note">A confirmation email with your invoice is on its way.</p>
                <a class="btn-ghost" href="{{ $order->invoiceUrl() }}">Download invoice (PDF)</a>
            @else
                <p class="cr-note">A confirmation email with your invoice will follow once payment is complete.</p>
            @endif
            <a class="btn-primary" href="{{ route('home') }}">Continue browsing</a>
        </div>
    </section>
@endsection
