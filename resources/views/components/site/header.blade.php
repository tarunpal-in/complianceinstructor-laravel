@php
    // Static for now. CLAUDE.md §5.6 gives this a `navigation_items` table with a
    // Filament manager in P6; the shape below is what that will hydrate into.
    $primary = [
        ['label' => 'Home', 'href' => '/'],
        ['label' => 'About', 'href' => '#'],
        ['label' => 'Training', 'href' => '#', 'children' => [
            ['label' => 'Live Webinars', 'href' => '#'],
            ['label' => 'Recorded Webinars', 'href' => '#'],
            ['label' => 'Packages', 'href' => '#'],
            ['label' => 'E-Transcripts', 'href' => '#'],
        ]],
        ['label' => 'Industry', 'href' => '#', 'children' => [
            ['label' => 'Human Resources', 'href' => '#'],
            ['label' => 'Manufacturing', 'href' => '#'],
            ['label' => 'Microsoft Office', 'href' => '#'],
            ['label' => 'Payroll', 'href' => '#'],
        ]],
        ['label' => 'Speakers', 'href' => '#'],
        ['label' => 'Memberships', 'href' => '#'],
        ['label' => 'Blog', 'href' => '#'],
        ['label' => 'Quiz', 'href' => '#'],
        ['label' => 'Help', 'href' => '#'],
    ];
@endphp

<header
    x-data="{ mobileOpen: false }"
    @keydown.escape.window="mobileOpen = false"
    class="sticky top-0 z-50 border-b border-navy-100 bg-white/95 backdrop-blur-sm"
>
    <div class="mx-auto flex h-16 max-w-7xl items-center gap-x-4 px-4 sm:px-6 lg:h-[72px] lg:px-8">

        {{-- Logo. Placeholder mark until brand assets land at §7 P0. --}}
        <a href="/" class="flex shrink-0 items-center gap-x-2.5" aria-label="{{ config('app.name') }} — home">
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-navy-900 text-gold-400" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
                    <path d="M6 12v5c3 3 9 3 12 0v-5" />
                </svg>
            </span>
            <span class="hidden text-[15px] font-bold leading-tight tracking-tight sm:block lg:text-base">
                Compliance<span class="text-gold-600">Instructor</span>
            </span>
        </a>

        {{-- Desktop navigation --}}
        <nav class="ms-2 hidden flex-1 items-center justify-center xl:flex" aria-label="Primary">
            <ul class="flex items-center gap-x-0.5">
                @foreach ($primary as $item)
                    <li @isset($item['children']) x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false" class="relative" @endisset>
                        @isset($item['children'])
                            <button
                                type="button"
                                @click="open = !open"
                                :aria-expanded="open ? 'true' : 'false'"
                                class="flex items-center gap-x-1 rounded-md px-3 py-2 text-sm font-medium text-navy-800 transition hover:bg-navy-50 hover:text-navy-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-600"
                            >
                                {{ $item['label'] }}
                                <svg class="h-3.5 w-3.5 transition-transform" :class="open && '-rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <div
                                x-show="open"
                                x-transition.opacity.duration.150ms
                                x-cloak
                                class="absolute start-0 top-full w-56 pt-2"
                            >
                                <ul class="rounded-xl border border-navy-100 bg-white p-1.5 shadow-lg shadow-navy-900/5">
                                    @foreach ($item['children'] as $child)
                                        <li>
                                            <a href="{{ $child['href'] }}" class="block rounded-lg px-3 py-2 text-sm text-navy-700 transition hover:bg-cream-100 hover:text-navy-950">
                                                {{ $child['label'] }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <a href="{{ $item['href'] }}" class="block rounded-md px-3 py-2 text-sm font-medium text-navy-800 transition hover:bg-navy-50 hover:text-navy-950">
                                {{ $item['label'] }}
                            </a>
                        @endisset
                    </li>
                @endforeach
            </ul>
        </nav>

        {{-- Utilities --}}
        <div class="ms-auto flex items-center gap-x-1 xl:ms-0 xl:gap-x-2">
            <button type="button" class="grid h-10 w-10 place-items-center rounded-full text-navy-700 transition hover:bg-navy-50 hover:text-navy-950" aria-label="Search">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
                </svg>
            </button>

            <a href="#" class="relative grid h-10 w-10 place-items-center rounded-full text-navy-700 transition hover:bg-navy-50 hover:text-navy-950" aria-label="Cart, 0 items">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 3h2l2.4 12.3a2 2 0 0 0 2 1.7h7.7a2 2 0 0 0 2-1.6L21 7H6" />
                    <circle cx="10" cy="20" r="1" /><circle cx="18" cy="20" r="1" />
                </svg>
            </a>

            <a href="#" class="ms-1 hidden rounded-full bg-gold-500 px-5 py-2.5 text-sm font-semibold text-navy-950 shadow-sm transition hover:bg-gold-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-600 sm:block">
                Login / Sign Up
            </a>

            <button
                type="button"
                @click="mobileOpen = !mobileOpen"
                :aria-expanded="mobileOpen ? 'true' : 'false'"
                aria-controls="mobile-nav"
                class="grid h-10 w-10 place-items-center rounded-full text-navy-800 transition hover:bg-navy-50 xl:hidden"
            >
                <span class="sr-only">Toggle navigation</span>
                <svg x-show="! mobileOpen" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16" />
                </svg>
                <svg x-show="mobileOpen" x-cloak class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>
    </div>

    {{-- Mobile navigation --}}
    <div
        id="mobile-nav"
        x-show="mobileOpen"
        x-cloak
        x-collapse
        class="border-t border-navy-100 bg-white xl:hidden"
    >
        <nav class="mx-auto max-w-7xl px-4 py-4 sm:px-6" aria-label="Mobile">
            <ul class="divide-y divide-navy-50">
                @foreach ($primary as $item)
                    <li x-data="{ sub: false }" class="py-1">
                        @isset($item['children'])
                            <button type="button" @click="sub = !sub" :aria-expanded="sub ? 'true' : 'false'" class="flex w-full items-center justify-between rounded-lg px-2 py-2.5 text-left text-[15px] font-medium text-navy-900">
                                {{ $item['label'] }}
                                <svg class="h-4 w-4 transition-transform" :class="sub && '-rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <ul x-show="sub" x-cloak x-collapse class="ps-3">
                                @foreach ($item['children'] as $child)
                                    <li><a href="{{ $child['href'] }}" class="block rounded-lg px-2 py-2 text-sm text-navy-600">{{ $child['label'] }}</a></li>
                                @endforeach
                            </ul>
                        @else
                            <a href="{{ $item['href'] }}" class="block rounded-lg px-2 py-2.5 text-[15px] font-medium text-navy-900">{{ $item['label'] }}</a>
                        @endisset
                    </li>
                @endforeach
            </ul>

            <a href="#" class="mt-4 block rounded-full bg-gold-500 px-5 py-3 text-center text-sm font-semibold text-navy-950 sm:hidden">
                Login / Sign Up
            </a>
        </nav>
    </div>
</header>
