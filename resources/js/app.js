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

/**
 * The webinar detail page's purchase panel.
 *
 * The total is recomputed here only to show the customer what they are about to
 * pay. CLAUDE.md §14 #1 — the server recomputes every total from
 * `offerings.price_cents` inside the order transaction, and never reads an
 * amount from the request. The predecessor took its charge amount from a hidden
 * `final_amount` field and passed it straight to PayPal.
 */
Alpine.data('purchasePanel', (offerings) => ({
    offerings,
    quantities: Object.fromEntries(Object.keys(offerings).map((id) => [id, 0])),
    total: 0,

    recalculate() {
        this.total = Object.entries(this.quantities).reduce(
            (sum, [id, qty]) => sum + (this.offerings[id]?.price ?? 0) * (Number(qty) || 0),
            0,
        );
    },

    get formattedTotal() {
        // Integer cents throughout (§4 rule 6); only the final render divides.
        return '$' + (this.total / 100).toLocaleString('en-US', { minimumFractionDigits: 0 });
    },

    addToCart() {
        if (this.total === 0) return;

        // The cart is P3. Until then this confirms the selection rather than
        // pretending to have taken it.
        this.$dispatch('notice', 'Checkout opens once the cart is live.');
    },
}));

/**
 * An accessible tooltip — WCAG 2.1 §1.4.13.
 *
 * Hover alone fails it: unreachable by keyboard, and on touch a tap either does
 * nothing or activates the row behind it. So it opens on focus and on click as
 * well, closes on Escape, and survives the pointer travelling onto it.
 */
Alpine.data('tooltip', () => ({
    shown: false,
    closeTimer: null,

    open() {
        clearTimeout(this.closeTimer);
        this.shown = true;
    },

    close() {
        clearTimeout(this.closeTimer);
        this.shown = false;
    },

    toggle() {
        this.shown ? this.close() : this.open();
    },

    // A short grace period so moving the pointer from the trigger onto the
    // tooltip does not dismiss it mid-journey.
    scheduleClose() {
        clearTimeout(this.closeTimer);
        this.closeTimer = setTimeout(() => (this.shown = false), 150);
    },
}));

/** Highlights whichever section of the webinar page is currently in view. */
Alpine.data('webinarTabs', () => ({
    active: '',

    init() {
        const sections = Array.from(document.querySelectorAll('.wd-section[id]'));

        if (sections.length === 0 || !('IntersectionObserver' in window)) return;

        this.active = sections[0].id;

        // The top band only: a section is "current" once its heading reaches the
        // upper quarter, which matches what a reader is actually looking at
        // better than "any part is visible" does on long sections.
        const observer = new IntersectionObserver(
            (entries) => {
                const visible = entries.filter((entry) => entry.isIntersecting);
                if (visible.length > 0) this.active = visible[0].target.id;
            },
            { rootMargin: '-96px 0px -70% 0px', threshold: 0 },
        );

        sections.forEach((section) => observer.observe(section));
    },
}));

window.Alpine = Alpine;

Alpine.start();
