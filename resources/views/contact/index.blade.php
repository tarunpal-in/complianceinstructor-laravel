@extends('layouts.public')


@section('content')
    {{-- Editable hero (Core Pages → Contact). --}}
    <x-site.page-hero :page="$corePage" />

    @php
        $email = \App\Support\Business::email();
        $phone = \App\Support\Business::phone();
        $address = \App\Support\Business::address();
        $hasDetails = $email || $phone || $address;
    @endphp

    <section class="section pb-contact" id="contact">
        {{-- Details beside the form when we have any; otherwise the form stands
             alone and centred, so it never floats against an empty column. --}}
        <div @class(['pb-contact-grid' => $hasDetails, 'pb-contact-solo' => ! $hasDetails])>
            @if ($hasDetails)
                <aside class="pb-contact-info">
                    @if ($email)
                        <div class="pb-contact-card">
                            <span class="pb-contact-icon"><x-ui.icon name="mail" :size="20" /></span>
                            <div class="pb-contact-card-body">
                                <strong>Email</strong>
                                <a href="mailto:{{ $email }}">{{ $email }}</a>
                            </div>
                        </div>
                    @endif

                    @if ($phone)
                        <div class="pb-contact-card">
                            <span class="pb-contact-icon"><x-ui.icon name="phone" :size="20" /></span>
                            <div class="pb-contact-card-body">
                                <strong>Phone</strong>
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a>
                            </div>
                        </div>
                    @endif

                    @if ($address)
                        <div class="pb-contact-card">
                            <span class="pb-contact-icon"><x-ui.icon name="map-pin" :size="20" /></span>
                            <div class="pb-contact-card-body">
                                <strong>Address</strong>
                                <span>{!! nl2br(e($address)) !!}</span>
                            </div>
                        </div>
                    @endif
                </aside>
            @endif

            <div class="pb-contact-form">
                <livewire:contact-form />
            </div>
        </div>
    </section>

    <x-site.copy-blocks :sections="$corePage?->sections" />

    <x-site.faq :items="$corePage?->faqItems() ?? []" heading="Before you write" />
@endsection
