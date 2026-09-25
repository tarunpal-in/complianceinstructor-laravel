@extends('layouts.public')

@section('title', 'Unsubscribed — '.config('app.name'))
@section('meta_robots', 'noindex')

@section('content')
    <section class="section mailing-notice">
        <h1>You are unsubscribed</h1>

        <p>
            We will not send {{ $subscriber->email }} any more marketing email.
        </p>

        <p class="mailing-notice-small">
            Changed your mind? The signup box at the bottom of any page will put
            you back on.
        </p>

        <a class="mailing-notice-button" href="{{ route('home') }}">Back to the site</a>
    </section>
@endsection
