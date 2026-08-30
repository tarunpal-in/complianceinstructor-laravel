@extends('layouts.public')

@section('title', 'Compliance Instructor — Professional Compliance Training')
@section('meta_description', 'Live and recorded compliance webinars led by certified industry experts. Earn HRCI, SHRM and NASBA/CPE recertification credits, with study materials, certificates and transcripts.')

@section('content')
    {{-- Section order matches the comp. Each is an anonymous component rather
         than a partial, so P6 can back the same file with a `page_sections`
         block without rewriting it (CLAUDE.md §2). --}}
    <x-home.hero :stats="$stats" :bodies="$bodies" />
    <x-home.process :steps="$steps" />
    <x-home.webinars :webinars="$webinars" />
    <x-home.categories :categories="$categories" />
    <x-home.benefits :benefits="$benefits" />
    <x-home.faculty :speakers="$speakers" />
    <x-home.accreditation :partners="$partners" />
    <x-home.stories :stories="$stories" />
    <x-home.pricing :plans="$plans" />
@endsection
