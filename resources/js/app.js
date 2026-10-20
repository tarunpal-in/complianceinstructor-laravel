// CLAUDE.md §3 — Blade + Livewire + Alpine. Livewire 3 ships its own Alpine and
// starts it, so we do NOT import or start a second Alpine (two instances clash).
// The public layout loads @livewireScripts on every page, and our components
// register against Livewire's Alpine via the alpine:init event below.
import Lenis from 'lenis';

/**
 * Momentum ("buttery") smooth scrolling for the marketing frontend.
 *
 * Lenis v1 drives the real scroll position (no transform wrapper), so
 * position:sticky and position:fixed — the cart summary, filter rails, the
 * webinar tab bar, the toast and modals — keep working. Native CSS
 * `scroll-behavior: smooth` stays as the fallback and is the only behaviour
 * under prefers-reduced-motion, where Lenis is not started at all.
 */
function initSmoothScroll() {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (reduced.matches || window.__lenis) return;

    let lenis;
    try {
        lenis = new Lenis({
            duration: 1.1,
            easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
            smoothWheel: true,
        });
    } catch {
        return; // Leaves the CSS smooth-scroll fallback in place.
    }

    window.__lenis = lenis;

    const raf = (time) => {
        lenis.raf(time);
        requestAnimationFrame(raf);
    };
    requestAnimationFrame(raf);

    // In-page anchor links: hand the jump to Lenis so it eases rather than
    // snapping, honouring each target's scroll-margin-top (e.g. the sticky
    // webinar tab bar).
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link) return;

        let url;
        try {
            url = new URL(link.href, window.location.href);
        } catch {
            return;
        }

        // Same-page hash links only.
        if (!url.hash || url.pathname !== window.location.pathname) return;

        const target = document.getElementById(decodeURIComponent(url.hash.slice(1)));
        if (!target) return;

        event.preventDefault();
        const offset = parseFloat(getComputedStyle(target).scrollMarginTop) || 0;
        lenis.scrollTo(target, { offset: -offset });
        history.pushState(null, '', url.hash);
    });
}

initSmoothScroll();

document.addEventListener('alpine:init', () => {
// The marketing pages need only what the comp does: mobile nav, the search
// panel, and the toast.
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

/**
 * The homepage "Webinar Categories" slider (an admin-chosen layout).
 *
 * A scroll-snap track moved by the prev/next buttons — no carousel library, just
 * native smooth scrolling. It wraps at the ends so paging never dead-ends, and
 * auto-advance (optional) pauses while the pointer is over the slider.
 */
Alpine.data('categorySlider', (opts = {}) => ({
    autoplay: Boolean(opts.autoplay),
    timer: null,

    init() {
        this.resume();
    },

    page(direction) {
        const track = this.$refs.track;
        if (!track) return;

        const atStart = track.scrollLeft <= 1;
        const atEnd = Math.ceil(track.scrollLeft + track.clientWidth) >= track.scrollWidth;

        // Wrap around rather than dead-end at either edge.
        if (direction > 0 && atEnd) {
            track.scrollTo({ left: 0, behavior: 'smooth' });
        } else if (direction < 0 && atStart) {
            track.scrollTo({ left: track.scrollWidth, behavior: 'smooth' });
        } else {
            track.scrollBy({ left: track.clientWidth * 0.9 * direction, behavior: 'smooth' });
        }
    },

    next() {
        this.resume(); // a manual move resets the auto-advance clock
        this.page(1);
    },

    prev() {
        this.resume();
        this.page(-1);
    },

    pause() {
        clearInterval(this.timer);
        this.timer = null;
    },

    resume() {
        this.pause();
        if (this.autoplay) this.timer = setInterval(() => this.page(1), 4500);
    },
}));

/**
 * The hero image slider — crossfades through the admin's uploaded images.
 *
 * Only mounted when there is more than one image (a single image renders as a
 * plain <img>), so here `count` is always ≥ 2. Pure opacity crossfade; the CSS
 * carries the transition and honours prefers-reduced-motion.
 */
Alpine.data('heroSlides', (opts = {}) => ({
    i: 0,
    count: Number(opts.count) || 1,
    timer: null,

    init() {
        if (this.count > 1) {
            this.timer = setInterval(() => {
                this.i = (this.i + 1) % this.count;
            }, 5000);
        }
    },

    destroy() {
        clearInterval(this.timer);
    },
}));

/**
 * The "back to top" button — shows once the page is scrolled, and eases to the
 * top through Lenis when it is running (native smooth scroll otherwise).
 */
Alpine.data('scrollTop', () => ({
    shown: false,

    init() {
        const update = () => {
            this.shown = window.scrollY > 600;
        };

        update();
        window.addEventListener('scroll', update, { passive: true });
    },

    toTop() {
        if (window.__lenis) {
            window.__lenis.scrollTo(0);
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
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
});
