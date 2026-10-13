@extends('layouts.public')


@section('content')
    {{-- The homepage is a builder-driven Page (CLAUDE.md §2 / §5.6). Each section
         below renders its block when present and visible; on an unseeded install
         ($homeManaged false) it renders the comp-default component instead, so
         the front door is never blank. A seeded-but-hidden section renders
         nothing. Order is fixed here — the editor reorders within it. --}}
    @php
        $has = fn (string $type) => $sections->get($type);
    @endphp

    {{-- Hero --}}
    @if ($block = $has('home_hero'))
        @include('home.blocks.home_hero', ['block' => $block])
    @elseif (! $homeManaged)
        <x-home.hero />
    @endif

    {{-- How It Works --}}
    @if ($block = $has('home_process'))
        @include('home.blocks.home_process', ['block' => $block])
    @elseif (! $homeManaged)
        <x-home.process />
    @endif

    {{-- Upcoming Webinars --}}
    @if ($block = $has('home_webinars'))
        @include('home.blocks.home_webinars', ['block' => $block])
    @elseif (! $homeManaged)
        <x-home.webinars />
    @endif

    {{-- Webinar Categories --}}
    @if ($block = $has('home_categories'))
        @include('home.blocks.home_categories', ['block' => $block])
    @elseif (! $homeManaged)
        <x-home.categories />
    @endif

    {{-- Benefits --}}
    @if ($block = $has('home_benefits'))
        @include('home.blocks.home_benefits', ['block' => $block])
    @elseif (! $homeManaged)
        <x-home.benefits />
    @endif

    {{-- Experienced Faculty --}}
    @if ($block = $has('home_faculty'))
        @include('home.blocks.home_faculty', ['block' => $block])
    @elseif (! $homeManaged)
        <x-home.faculty />
    @endif

    {{-- Accreditation Partners --}}
    @if ($block = $has('home_accreditation'))
        @include('home.blocks.home_accreditation', ['block' => $block])
    @elseif (! $homeManaged)
        <x-home.accreditation />
    @endif

    {{-- Success Stories --}}
    @if ($block = $has('home_stories'))
        @include('home.blocks.home_stories', ['block' => $block])
    @elseif (! $homeManaged)
        <x-home.stories />
    @endif

    {{-- Membership Plans — the list is always the live plans. --}}
    @if ($block = $has('home_pricing'))
        @include('home.blocks.home_pricing', ['block' => $block, 'plans' => $plans])
    @elseif (! $homeManaged)
        <x-home.pricing :plans="$plans" />
    @endif
@endsection
