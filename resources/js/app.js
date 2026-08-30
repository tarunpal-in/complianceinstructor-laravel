import Alpine from 'alpinejs';

// CLAUDE.md §3 — Blade + Livewire + Alpine, with Livewire reserved for places
// where state is real (cart, checkout, catalogue filters). The marketing pages
// need only what the comp does: mobile nav, the search panel, and the toast.
Alpine.data('site', () => ({
    mobileOpen: false,
    searchOpen: false,
    notice: '',
    timer: null,

    showNotice(message) {
        this.notice = message;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => (this.notice = ''), 2600);
    },
}));

window.Alpine = Alpine;

Alpine.start();
