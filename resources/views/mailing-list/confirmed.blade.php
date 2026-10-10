@extends('layouts.public')


@section('content')
    <section class="section mailing-notice">
        <h1>You are on the list</h1>

        <p>
            {{ $subscriber->email }} is confirmed. You will hear from us when
            there is something worth reading.
        </p>

        <p class="mailing-notice-small">
            Every email we send has an unsubscribe link at the bottom.
        </p>

        <a class="mailing-notice-button" href="{{ route('home') }}">Back to the site</a>
    </section>
@endsection
