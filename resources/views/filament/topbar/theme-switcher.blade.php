{{--
    Theme switcher, collapsed to a single control.

    Filament ships three side-by-side buttons (sun / moon / desktop) inside the
    user-menu dropdown. This replaces them with one trigger showing the active
    mode, opening a menu on hover or click.

    The mechanism is Filament's own: dark-mode.js listens on window for a
    `theme-changed` event and takes the mode from event.detail, writes it to
    localStorage and toggles `.dark` on <html>. Dispatching that event is all
    this needs to do — no styling logic lives here.
--}}
<div
    x-data="{
        open: false,
        theme: 'system',
        init() {
            this.theme = localStorage.getItem('theme') || 'system'
            this.$watch('theme', (mode) => this.$dispatch('theme-changed', mode))
        },
        pick(mode) {
            this.theme = mode
            this.open = false
        },
    }"
    x-on:mouseenter="open = true"
    x-on:mouseleave="open = false"
    x-on:keydown.escape.window="open = false"
    class="ci-theme"
>
    <button
        type="button"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="menu"
        class="ci-theme-trigger"
    >
        <span class="sr-only">Change theme</span>

        <svg x-show="theme === 'light'" x-cloak class="ci-theme-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>

        <svg x-show="theme === 'dark'" x-cloak class="ci-theme-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>

        <svg x-show="theme === 'system'" class="ci-theme-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25" /></svg>
    </button>

    {{-- No gap between trigger and panel: the padding-top keeps the pointer
         inside the hover target while travelling down to the menu. --}}
    <div x-show="open" x-cloak x-transition.opacity.duration.150ms class="ci-theme-menu" role="menu">
        <div class="ci-theme-menu-panel">
            @foreach ([
                ['light', 'Light', 'M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z'],
                ['dark', 'Dark', 'M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z'],
                ['system', 'System', 'M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25'],
            ] as [$mode, $label, $path])
                <button
                    type="button"
                    role="menuitemradio"
                    x-on:click="pick(@js($mode))"
                    x-bind:aria-checked="theme === @js($mode) ? 'true' : 'false'"
                    x-bind:class="{ 'ci-theme-item-active': theme === @js($mode) }"
                    class="ci-theme-item"
                >
                    <svg class="ci-theme-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}" /></svg>
                    {{ $label }}
                    <svg x-show="theme === @js($mode)" x-cloak class="ci-theme-check" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
                </button>
            @endforeach
        </div>
    </div>
</div>
