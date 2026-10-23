@extends('layouts.public')

@section('content')
    {{-- Editable hero (Core Pages → Membership). --}}
    <x-site.page-hero :page="$corePage" />

    {{-- The plan cards are the shared pricing component, driven by the live
         membership_plans (§5.5): the badge a customer reads is the allowance
         they are granted, because both come from plan_entitlements. --}}
    {{-- The "Request a Callback" button under the plan cards is editable on the
         Membership core page: its label and link. A blank link shows a
         "we'll be in touch" message instead of a hyperlink. --}}
    <x-home.pricing
        :plans="$plans"
        heading="Choose your membership"
        intro="Every plan includes continuing-education credit. Upgrade or downgrade whenever your needs change."
        :callback-button="$corePage?->cta_label"
        :callback-url="$corePage?->cta_url"
    />

    <x-site.copy-blocks :sections="$corePage?->sections" />

    <x-site.faq :items="$corePage?->faqItems() ?? []" />
@endsection
