# SEO — how it works, and what's built

> Source of requirements: `docs/seo-requirements.pdf` (client, 2026-10). This
> implements CLAUDE.md §5.6 / §6's "Page builder & SEO" (P6). Built in three
> phases; **this document covers Phase 1 (the editable core)** and notes what
> Phases 2–3 add.

## Locked decisions

| Decision | Choice | Why |
|---|---|---|
| Canonical URL form | **Trailing slash** (`/webinars/foo/`) | Matches the live WordPress URLs, so the migration triggers no mass 301s |
| "Landing pages" | = webinar pages | Client's words; no separate landing type |
| Analytics | GA4 + GTM + Tawk.to | Settings fields (Phase 3) |
| Focus keyword | Internal only — **never a meta tag** | Google ignores `meta keywords` and treats a stuffed one as spam |

## Phase 1 — what's built

**Editable per-page SEO** on webinars, articles, speakers, pages and both
category types (`App\Models\Concerns\HasSeo` + the `seo_meta` table). Each field
is an override with a computed fallback, so the common case is to leave it blank.
Edited from a shared **"Search engine optimisation"** panel on every content
resource (`App\Filament\Forms\Components\SeoFields`), with a live result preview.

**The rendering** is driven by one resolver, `App\Seo\Seo`, producing a
`SeoData` object that the head partial (`resources/views/partials/seo-head.blade.php`)
prints. A view gets its `$seo` from its controller; anything that doesn't gets a
sensible site-level default. The fallback chain lives in one place so titles and
descriptions are never auto-duplicated (brief §3).

**Canonical + duplicate-URL control** (brief §7): the canonical is always
absolute, HTTPS, trailing-slash, and query-free — so `?utm_source=…` and
trailing-slash variants never fork a page into duplicates. Built by hand in
`Seo::canonicalForCurrent()`, because Laravel's URL generator trims trailing
slashes.

**Trailing-slash enforcement**: `App\Http\Middleware\NormalizeUrl` 301s a bare
public content URL to its trailing-slash form. Public content only — admin,
Livewire, the API, signed/POST routes (mailing-list, newsletter, preview) and
files are excluded.

### Two framework limitations worth knowing

1. **`route()` / `url()` emit bare URLs.** Laravel's `UrlGenerator::format()`
   ends with `trim($root.$path, '/')`, stripping any trailing slash, and there
   is no clean hook around it (`formatPathUsing` runs before the trim). So
   internal links are bare and a visitor clicking one takes a single 301 hop to
   the trailing form. The canonical tag is trailing regardless, so Google indexes
   the right URL. Eliminating the hop would need a custom `UrlGenerator`
   subclass — deferred as not worth the fragility.

2. **The test HTTP client strips trailing slashes** from requested URLs
   (`MakesHttpRequests::prepareUrlForRequest`), which would make the redirect
   loop in tests. So the redirect is gated behind `config('seo.trailing_slash_redirect')`,
   set `false` in `phpunit.xml` and `true` everywhere else; the middleware's
   redirect logic is unit-tested directly with a crafted request instead.

### The one hard rule

No `meta keywords` tag, ever. `SeoData` has no field for it, the renderer has no
path to emit it, and `SeoTest` fails the build if the string appears in any view
— including in a comment.

## To run

```
php artisan migrate
```

Adds the `seo_meta` table. `migrate:fresh --seed` also attaches a few example
overrides (`SeoMetaSeeder`) so the review environment shows the feature used.

## Not yet built (Phases 2–3)

- **Phase 2:** redirects table + manager (with loop/chain guards), auto-301 on
  slug change, custom 404, `robots.txt`, XML sitemap, legacy WordPress URL import.
- **Phase 3:** schema.org JSON-LD (Organization, WebSite, Breadcrumb, Article,
  Event, Product), breadcrumbs, og:image refinements, WebP/responsive images,
  GA4/GTM/Tawk.to settings, FAQ block, and the SEO health dashboard.

Category pages are SEO-editable in the admin now (the trait is on `Industry` and
`PostCategory`), but they have no dedicated public URL yet — a category is a
`?category=` filter on `/blog/`, which canonicalises to `/blog/`. Wiring the
editable fields to real category URLs waits on those URLs existing.
