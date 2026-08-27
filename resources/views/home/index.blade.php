@extends('layouts.public')

@section('title', 'Compliance Instructor — HRCI, SHRM & CPE Accredited Training Webinars')
@section('meta_description', 'Live and recorded compliance webinars led by certified industry experts. Earn HRCI, SHRM and NASBA/CPE recertification credits, with study materials, certificates and transcripts.')

@section('content')
    {{-- Each section is an anonymous component rather than a partial, so that in
         P6 the same file can back a `page_sections` block without being rewritten
         (CLAUDE.md §2 — one page builder, no model per section). --}}
    <x-home.hero :stats="$stats" :bodies="$bodies" />
    <x-home.how-it-works :steps="$steps" />
    <x-home.upcoming-webinars :webinars="$webinars" />
    <x-home.categories :categories="$categories" />
    <x-home.value-band :values="$values" />
    <x-home.faculty :speakers="$speakers" />
    <x-home.accreditations :partners="$partners" />
    <x-home.testimonials :testimonials="$testimonials" />
    <x-home.pricing :plans="$plans" />
@endsection
