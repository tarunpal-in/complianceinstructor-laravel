{{-- Brand skin for the learner panel — the public site's colours (deep blue,
     gold, warm cream) carried into Filament's chrome and the learner cards.
     Injected into <head> for every account page via the panel render hook, so
     it does not depend on Filament's Tailwind build scanning these views. --}}
<style>
    :root {
        --ld-blue: #0c4e80;
        --ld-blue-deep: #0a3c63;
        --ld-heading: #054376;
        --ld-gold: #f8ad1b;
        --ld-gold-dark: #f6a406;
        --ld-cream: #fbf6ec;
        --ld-ink: #101a2c;
        --ld-muted: #5a6472;
        --ld-line: #e9e3d6;
    }

    /* Page & top bar ----------------------------------------------------- */
    .fi-main-ctn, .fi-main { background: #f7f3ea; }
    .fi-topbar > nav, .fi-topbar nav { background: #fff; border-bottom: 1px solid var(--ld-line); }
    .fi-topbar .fi-logo, .fi-sidebar-header .fi-logo { color: var(--ld-heading); font-weight: 800; letter-spacing: -0.01em; }

    /* Sidebar ------------------------------------------------------------ */
    .fi-sidebar, .fi-sidebar-nav { background: #fff; }
    .fi-sidebar { border-right: 1px solid var(--ld-line); }
    .fi-sidebar-nav { gap: .2rem; padding-top: .5rem; }

    .fi-sidebar-item a, .fi-sidebar-item button, .fi-sidebar-item-button {
        padding: .72rem .8rem; gap: .85rem; border-radius: .7rem; color: #233140; transition: background .15s ease, color .15s ease;
    }
    .fi-sidebar-item-label { font-size: .95rem; font-weight: 600; color: inherit !important; }
    .fi-sidebar-item-icon { width: 1.4rem; height: 1.4rem; color: var(--ld-blue); }

    .fi-sidebar-item a:hover, .fi-sidebar-item button:hover, .fi-sidebar-item-button:hover {
        background: var(--ld-cream); color: var(--ld-heading);
    }
    .fi-sidebar-item a:hover .fi-sidebar-item-icon, .fi-sidebar-item button:hover .fi-sidebar-item-icon { color: var(--ld-heading); }

    /* Active item — a filled brand-blue pill with white label and icon. The
       label and icon are forced white too, or Filament's own colour wins and
       the text reads blue-on-blue. */
    .fi-sidebar-item-active a,
    .fi-sidebar-item a[aria-current="page"],
    .fi-sidebar-item button[aria-current="page"],
    .fi-sidebar-item.fi-active a,
    .fi-sidebar-item.fi-active button {
        background: linear-gradient(135deg, var(--ld-blue), var(--ld-blue-deep)) !important;
        color: #fff !important; box-shadow: 0 10px 24px rgba(12, 78, 128, .28);
    }
    .fi-sidebar-item-active .fi-sidebar-item-label,
    .fi-sidebar-item a[aria-current="page"] .fi-sidebar-item-label,
    .fi-sidebar-item button[aria-current="page"] .fi-sidebar-item-label,
    .fi-sidebar-item.fi-active .fi-sidebar-item-label,
    .fi-sidebar-item-active .fi-sidebar-item-icon,
    .fi-sidebar-item a[aria-current="page"] .fi-sidebar-item-icon,
    .fi-sidebar-item button[aria-current="page"] .fi-sidebar-item-icon,
    .fi-sidebar-item.fi-active .fi-sidebar-item-icon { color: #fff !important; }

    /* "Go to site" links pinned under the nav. */
    .ld-site-links { margin-top: 1.25rem; padding: 1rem .5rem 0; border-top: 1px solid var(--ld-line); display: grid; gap: .5rem; }
    .ld-site-link {
        display: flex; align-items: center; gap: .75rem; padding: .7rem .8rem; border-radius: .7rem;
        font-size: .95rem; font-weight: 700; text-decoration: none; transition: background .15s ease, color .15s ease;
    }
    .ld-site-link.primary { background: var(--ld-gold); color: var(--ld-ink); }
    .ld-site-link.primary:hover { background: var(--ld-gold-dark); }
    .ld-site-link.ghost { color: #233140; }
    .ld-site-link.ghost:hover { background: var(--ld-cream); color: var(--ld-heading); }
    .ld-site-link svg { width: 1.35rem; height: 1.35rem; }

    /* Headings ----------------------------------------------------------- */
    .fi-header-heading, .fi-page-header-heading { color: var(--ld-heading); font-weight: 800; letter-spacing: -0.01em; }

    /* Learner cards ------------------------------------------------------ */
    .fi-page .fi-page-content { display: grid; gap: 1rem; }

    .ld-card {
        background: #fff; border: 1px solid #e6eaef; border-radius: 16px; padding: 1.5rem;
        display: flex; flex-direction: column; gap: 1.1rem; box-shadow: 0 10px 30px rgba(16, 26, 44, .05);
        transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
    }
    .ld-card:hover { border-color: #cfe0ee; box-shadow: 0 16px 40px rgba(16, 26, 44, .09); transform: translateY(-2px); }
    .ld-card-top { display: flex; flex-direction: column; gap: 1rem; }
    @media (min-width: 640px) { .ld-card-top { flex-direction: row; align-items: center; justify-content: space-between; } }

    .ld-card-main { min-width: 0; }
    .ld-card-title { font-size: 1.15rem; font-weight: 800; color: var(--ld-ink); margin: 0; }
    .ld-card-speaker { margin: .25rem 0 0; font-size: .9rem; color: var(--ld-muted); }
    .ld-card-date { margin: .4rem 0 0; font-size: .9rem; font-weight: 700; color: var(--ld-blue); }
    .ld-card-added { margin: .3rem 0 0; font-size: .82rem; color: var(--ld-muted); }

    /* Membership summary -------------------------------------------------- */
    .ld-mb-status { flex: none; display: inline-flex; align-items: center; padding: .3rem .8rem; border-radius: 999px; font-size: .7rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
    .ld-mb-status.is-active { background: #e4f3ea; color: #137a43; }
    .ld-mb-status.is-inactive { background: #f1f3f6; color: #7a828f; }
    .ld-mb-allowance { border-top: 1px solid #eef1f5; padding-top: 1.1rem; }
    .ld-mb-allowance-label { display: block; font-size: .72rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #8a93a1; margin-bottom: .7rem; }
    .ld-mb-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .55rem; }
    .ld-mb-list li { display: flex; align-items: center; justify-content: space-between; gap: 1rem; font-size: .95rem; padding: .15rem 0; }
    .ld-mb-list li span { color: var(--ld-muted); }
    .ld-mb-list li strong { color: var(--ld-ink); font-weight: 700; }

    .ld-card-action { flex: none; display: flex; flex-direction: column; gap: .6rem; }
    @media (min-width: 640px) { .ld-card-action { align-items: flex-end; } }

    /* Certificates ------------------------------------------------------- */
    .ld-cert-serial { margin: .5rem 0 0; font-size: .78rem; letter-spacing: .02em; color: #8a93a1; }
    .ld-cert-badge { display: inline-flex; align-items: center; padding: .3rem .8rem; border-radius: 999px; font-size: .7rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
    .ld-cert-badge.is-valid { background: #e4f3ea; color: #137a43; }
    .ld-cert-badge.is-revoked { background: #fdece9; color: #b42318; }
    .ld-btn {
        display: inline-flex; align-items: center; justify-content: center; width: 100%;
        background: var(--ld-gold); color: var(--ld-ink); font-weight: 800; font-size: .95rem;
        padding: .8rem 1.6rem; border-radius: .7rem; text-decoration: none; white-space: nowrap;
        transition: background .15s ease;
    }
    .ld-btn:hover { background: var(--ld-gold-dark); }
    @media (min-width: 640px) { .ld-btn { width: auto; } }

    .ld-pending {
        display: inline-flex; align-items: center; justify-content: center; width: 100%;
        background: #f1f3f6; color: #7a828f; font-size: .9rem; padding: .8rem 1.4rem; border-radius: .7rem;
    }
    @media (min-width: 640px) { .ld-pending { width: auto; } }

    /* Order history ------------------------------------------------------ */
    .ld-order-head { display: flex; flex-direction: column; gap: 1rem; }
    @media (min-width: 640px) { .ld-order-head { flex-direction: row; align-items: flex-start; justify-content: space-between; } }
    .ld-order-status { font-weight: 700; color: var(--ld-blue); }
    .ld-order-right { display: flex; flex-direction: column; align-items: flex-start; gap: .6rem; }
    @media (min-width: 640px) { .ld-order-right { align-items: flex-end; } }
    .ld-order-total { font-size: 1.15rem; font-weight: 800; color: var(--ld-ink); }

    .ld-order-items { list-style: none; margin: 0; padding: 1rem 0 0; border-top: 1px solid #eef1f5; display: grid; gap: .55rem; }
    .ld-order-items li { display: flex; align-items: baseline; gap: .75rem; font-size: .92rem; }
    .ld-order-item-desc { flex: 1; min-width: 0; color: var(--ld-ink); font-weight: 600; }
    .ld-order-item-qty { flex: none; color: var(--ld-muted); font-variant-numeric: tabular-nums; }
    .ld-order-item-amt { flex: none; color: var(--ld-ink); font-weight: 700; font-variant-numeric: tabular-nums; }

    /* Expired-access notice ---------------------------------------------- */
    .ld-notice {
        display: flex; flex-direction: column; gap: .5rem; align-items: flex-start;
        background: #fff6e6; border: 1px solid #f3dca6; border-radius: 12px; padding: .9rem 1.1rem;
        color: #7a5406; font-size: .92rem; font-weight: 600;
    }
    @media (min-width: 640px) { .ld-notice { flex-direction: row; align-items: center; justify-content: space-between; } }
    .ld-notice a { flex: none; color: var(--ld-ink); background: var(--ld-gold); font-weight: 800; text-decoration: none; padding: .5rem 1rem; border-radius: .6rem; transition: background .15s ease; }
    .ld-notice a:hover { background: var(--ld-gold-dark); }

    /* Study materials (handouts) ---------------------------------------- */
    .ld-handouts { border-top: 1px dashed #e6eaef; padding-top: 1rem; }
    .ld-handouts-label { display: block; font-size: .72rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #8a93a1; margin-bottom: .6rem; }
    .ld-handouts-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
    .ld-handout {
        display: flex; align-items: center; gap: .75rem; padding: .6rem .8rem; border-radius: .6rem;
        background: var(--ld-cream); border: 1px solid #efe7d6; text-decoration: none; transition: background .15s ease, border-color .15s ease;
    }
    .ld-handout:hover { background: #fbefd4; border-color: #f0dfb4; }
    .ld-handout-ext { flex: none; min-width: 2.6rem; text-align: center; font-size: .66rem; font-weight: 800; letter-spacing: .03em; color: #fff; background: var(--ld-blue); border-radius: .4rem; padding: .25rem .35rem; }
    .ld-handout-name { flex: 1; min-width: 0; color: var(--ld-ink); font-weight: 600; font-size: .92rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ld-handout-get { flex: none; color: var(--ld-blue); font-weight: 700; font-size: .85rem; }

    /* Empty state -------------------------------------------------------- */
    .ld-empty {
        display: grid; justify-items: center; gap: .9rem; text-align: center;
        background: var(--ld-cream); border: 1px solid var(--ld-line); border-radius: 18px; padding: 3.5rem 1.5rem;
    }
    .ld-empty-icon {
        width: 3.5rem; height: 3.5rem; border-radius: 50%; display: grid; place-items: center;
        background: #fff; color: var(--ld-blue); box-shadow: 0 6px 16px rgba(16, 26, 44, .06);
    }
    .ld-empty-text { margin: 0; max-width: 28rem; color: var(--ld-muted); font-size: 1rem; }
    .ld-empty-link {
        display: inline-flex; align-items: center; background: var(--ld-blue); color: #fff; font-weight: 700;
        padding: .7rem 1.6rem; border-radius: .7rem; text-decoration: none; transition: background .15s ease;
    }
    .ld-empty-link:hover { background: var(--ld-blue-deep); }
</style>
