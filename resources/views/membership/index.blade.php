@extends('layouts.public')

@section('content')
    {{-- Hero --}}
    <section class="mb-hero">
        <div class="mb-hero-inner">
            <span class="mb-eyebrow">MEMBERSHIPS</span>
            <h1>One plan, a year of credits</h1>
            <p>Pick the allowance that matches how your team learns — live sessions, recordings and transcripts across the accrediting bodies you report to. Cancel anytime; your access runs to the end of the term you paid for.</p>
        </div>
    </section>

    {{-- The plan cards are the shared pricing component, driven by the live
         membership_plans (§5.5): the badge a customer reads is the allowance
         they are granted, because both come from plan_entitlements. --}}
    <x-home.pricing
        :plans="$plans"
        heading="Choose your membership"
        intro="Every plan includes continuing-education credit. Upgrade or downgrade whenever your needs change."
    />
@endsection
