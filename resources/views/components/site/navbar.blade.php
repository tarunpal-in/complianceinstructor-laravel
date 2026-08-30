@php
    // Static for now. CLAUDE.md §5.6 gives this a `navigation_items` table with
    // a Filament manager in P6. Training and Industry carry a chevron in the
    // comp, so the flag is kept on the item rather than hardcoded in markup.
    $links = [
        ['label' => 'Home', 'chevron' => false],
        ['label' => 'About', 'chevron' => false],
        ['label' => 'Training', 'chevron' => true],
        ['label' => 'Industry', 'chevron' => true],
        ['label' => 'Speakers', 'chevron' => false],
        ['label' => 'Memberships', 'chevron' => false],
        ['label' => 'Blog', 'chevron' => false],
        ['label' => 'Quiz', 'chevron' => false],
        ['label' => 'Help', 'chevron' => false],
    ];
@endphp

<header class="navbar">
    <x-site.brand />

    <nav class="nav-links" :class="{ open: mobileOpen }" aria-label="Primary">
        @foreach ($links as $link)
            <a href="#{{ Str::lower($link['label']) }}" @click="mobileOpen = false">
                {{ $link['label'] }}@if ($link['chevron'])<x-ui.icon name="chevron-down" :size="13" />@endif
            </a>
        @endforeach
    </nav>

    <div class="nav-actions">
        <button class="icon-button" aria-label="Search" :aria-expanded="searchOpen ? 'true' : 'false'" @click="searchOpen = ! searchOpen">
            <span x-show="! searchOpen"><x-ui.icon name="search" :size="20" /></span>
            <span x-show="searchOpen" x-cloak><x-ui.icon name="x" :size="20" /></span>
        </button>

        <button class="icon-button" aria-label="Shopping cart" @click="showNotice('Your cart is ready for checkout')">
            <x-ui.icon name="shopping-cart" :size="20" />
        </button>

        <button class="login-button" @click="showNotice('Sign up is coming soon')">Login / Sign Up</button>

        <button class="mobile-menu" aria-label="Toggle menu" :aria-expanded="mobileOpen ? 'true' : 'false'" @click="mobileOpen = ! mobileOpen">
            <span x-show="! mobileOpen"><x-ui.icon name="menu" /></span>
            <span x-show="mobileOpen" x-cloak><x-ui.icon name="x" /></span>
        </button>
    </div>

    <div class="search-panel" x-show="searchOpen" x-cloak>
        <x-ui.icon name="search" :size="18" />
        <label for="site-search" class="sr-only">Search</label>
        <input id="site-search" placeholder="Search webinars, courses, and topics">
    </div>
</header>
