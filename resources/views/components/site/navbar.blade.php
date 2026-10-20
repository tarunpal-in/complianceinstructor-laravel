@php
    // The header menu from the navigation manager (§5.6), falling back to the
    // designed default until the client edits it. An item with children renders
    // a dropdown.
    $links = \App\Support\Navigation::header();
@endphp

<header class="navbar">
    <x-site.brand />

    <nav class="nav-links" :class="{ open: mobileOpen }" aria-label="Primary">
        @foreach ($links as $link)
            @php $href = $link['url'] ?: '#'.\Illuminate\Support\Str::slug($link['label']); @endphp
            @if (! empty($link['children']))
                {{-- Dropdown: the parent opens the submenu; it still navigates if
                     it has its own link. --}}
                <div class="nav-dropdown" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                    <a href="{{ $href }}" @if ($link['new_tab']) target="_blank" rel="noopener" @endif
                       @click="mobileOpen = false" aria-haspopup="true" :aria-expanded="open.toString()">
                        {{ $link['label'] }}<x-ui.icon name="chevron-down" :size="13" />
                    </a>
                    <div class="nav-submenu" x-show="open" x-cloak>
                        @foreach ($link['children'] as $child)
                            <a href="{{ $child['url'] ?: '#' }}"
                               @if ($child['new_tab']) target="_blank" rel="noopener" @endif
                               @click="mobileOpen = false">{{ $child['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @else
                <a href="{{ $href }}" @if ($link['new_tab']) target="_blank" rel="noopener" @endif @click="mobileOpen = false">
                    {{ $link['label'] }}
                </a>
            @endif
        @endforeach
    </nav>

    <div class="nav-actions">
        <button class="icon-button" aria-label="Search" :aria-expanded="searchOpen ? 'true' : 'false'" @click="searchOpen = ! searchOpen">
            <span x-show="! searchOpen"><x-ui.icon name="search" :size="20" /></span>
            <span x-show="searchOpen" x-cloak><x-ui.icon name="x" :size="20" /></span>
        </button>

        <livewire:cart-count />

        @auth
            {{-- Send each user to the panel they can actually open: staff to the
                 admin panel (the customer panel refuses them, which is the 403),
                 customers to their account area. --}}
            @php $isStaff = auth()->user()->isStaff(); @endphp
            <a class="login-button"
               href="{{ $isStaff ? \Filament\Facades\Filament::getPanel('admin')->getUrl() : route('filament.account.pages.upcoming-webinars') }}">
                {{ $isStaff ? 'Admin' : 'My Account' }}
            </a>
            <form method="POST" action="{{ route('logout') }}" class="nav-logout">
                @csrf
                <button type="submit" class="nav-logout-btn">Log out</button>
            </form>
        @else
            <a class="login-button" href="{{ route('login') }}">Login / Sign Up</a>
        @endauth

        <button class="mobile-menu" aria-label="Toggle menu" :aria-expanded="mobileOpen ? 'true' : 'false'" @click="mobileOpen = ! mobileOpen">
            <span x-show="! mobileOpen"><x-ui.icon name="menu" /></span>
            <span x-show="mobileOpen" x-cloak><x-ui.icon name="x" /></span>
        </button>
    </div>

    {{-- A plain GET form to the catalogue: submitting navigates to
         /webinars?q=… and the catalogue's URL-bound `q` filters the grid.
         Opening the panel focuses the box; Escape closes it. --}}
    <form class="search-panel" x-show="searchOpen" x-cloak method="GET" action="{{ route('webinars.index') }}"
          x-effect="if (searchOpen) $nextTick(() => $refs.searchInput && $refs.searchInput.focus())"
          @keydown.escape="searchOpen = false">
        <button type="submit" class="search-submit" aria-label="Search"><x-ui.icon name="search" :size="18" /></button>
        <label for="site-search" class="sr-only">Search</label>
        <input id="site-search" name="q" type="search" value="{{ request('q') }}" x-ref="searchInput"
               placeholder="Search webinars, courses, and topics">
    </form>
</header>
