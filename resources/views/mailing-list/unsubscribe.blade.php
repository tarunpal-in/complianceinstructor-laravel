@extends('layouts.public')


@section('content')
    <section class="section mailing-notice">
        <h1>Unsubscribe</h1>

        <p>
            {{ $subscriber->email }} will stop receiving emails from
            {{ config('app.name') }}.
        </p>

        {{-- A button rather than doing it on page load. Mail scanners fetch
             every link in an email, and a GET that acted would take people off
             the list who never clicked. --}}
        <form method="POST" action="{{ url()->signedRoute('mailing-list.unsubscribe.confirm', ['subscriber' => $subscriber->getKey()]) }}">
            @csrf
            <button type="submit" class="mailing-notice-button">Yes, unsubscribe me</button>
        </form>

        <p class="mailing-notice-small">
            This does not cancel a membership or affect anything you have bought.
            It only stops marketing email.
        </p>
    </section>
@endsection
