{{--
    Theme toggle — one button, flips light ↔ dark on click.

    No local state. Filament's dark-mode.js already maintains `$store.theme` as
    the resolved mode ('light' or 'dark'), listens on window for a
    `theme-changed` event, writes localStorage and toggles `.dark` on <html>.
    Alpine's $dispatch bubbles to window, so dispatching the opposite of the
    current store value is the whole implementation — and the icon updates
    reactively off the same store.

    Note this drops "system". Once toggled, localStorage holds an explicit
    'light' or 'dark' and no longer follows the OS setting.
--}}
<button
    x-data
    type="button"
    x-on:click="$dispatch('theme-changed', $store.theme === 'dark' ? 'light' : 'dark')"
    x-bind:aria-label="$store.theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
    x-bind:aria-pressed="$store.theme === 'dark' ? 'true' : 'false'"
    class="ci-theme-toggle"
>
    <svg x-show="$store.theme !== 'dark'" class="ci-theme-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>

    <svg x-show="$store.theme === 'dark'" x-cloak class="ci-theme-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>
</button>
