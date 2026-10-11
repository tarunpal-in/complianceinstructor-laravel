# SEO — how it works, and what's built

> Source of requirements: `docs/seo-requirements.pdf` (client, 2026-10). This
> implements CLAUDE.md §5.6 / §6's "Page builder & SEO" (P6). Built in three
> phases — the editable core (1), crawl/redirects/migration safety (2), and
> structured data/analytics/health (3) — all now complete.

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

### How trailing slashes reach every link

`route()` and `url()` emit trailing-slash URLs for public content, via a custom
`App\Routing\TrailingSlashUrlGenerator` (bound in `UrlServiceProvider`). This is
the only clean place to do it — Laravel's `UrlGenerator::format()` ends with
`trim($root.$path, '/')`, stripping any trailing slash, with no hook that
survives it. Generating the slash rather than redirecting to it means **internal
links point straight at the canonical form, with no 301 hop**. A guard test fails
the build if a view hardcodes a bare content link.

`NormalizeUrl` stays as the safety net: a bare URL from outside — an old link, a
typed address — still 301s to the trailing form.

Machinery (admin, Livewire, API, assets) and **signed routes** (preview,
mailing-list) are left bare. Signed routes especially: `hasCorrectSignature`
validates against an rtrimmed path, so a trailing slash on one would never match.
The decision about which paths take a slash lives in one place,
`App\Seo\TrailingSlash`, shared by the generator and the middleware.

### One framework quirk worth knowing

**The test HTTP client strips trailing slashes** from requested URLs
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

## Phase 2 — crawl, redirects & migration safety

**Redirects** (`redirects` table, `App\Models\Redirect`, managed under Content →
Redirects). Served by `HandleRedirects`, which runs before `NormalizeUrl` so a
moved URL goes straight to its destination. `destinationFor()` follows a chain to
its end so a visitor never bounces more than once, and refuses a loop. The
manager's save guards refuse a chain, a loop, or a source that would hijack a
live page (`Redirect::problemWith` / `shadowsLiveContent`); the CSV importer
(`App\Seo\RedirectImporter`, "Import CSV" on the list) runs the same guards per
row and skips bad ones with a reason.

**Auto-301 on slug change** (`HasSeo::bootHasSeo`): editing a webinar, article or
page slug writes `old → new` automatically, and renaming twice flattens the
chain so every old URL reaches the current one in one hop.

**Custom 404** (`resources/views/errors/404.blade.php`): message, search box,
home link, full site nav, links to key sections. Noindex (belt and braces beside
the 404 status). The blog gained a `?q=` text search for the box to use.

**robots.txt** (`/robots.txt`, dynamic): a blanket `Disallow: /` off production
so staging is never indexed; on production it allows crawling, blocks `/admin`
and the other machinery, never blocks CSS/JS, and points at the sitemap.

**XML sitemap** (`/sitemap.xml`, `App\Seo\Sitemap`): built from live, indexable
records — not a crawl — so drafts, noindex pages and redirected paths never
appear. Cached, and the cache is dropped whenever a webinar, article or page is
saved.

## Phase 3 — structured data, analytics & the health dashboard

**Schema.org JSON-LD** (`App\Seo\Schema`, rendered by
`resources/views/partials/schema.blade.php`). Every page carries `Organization`
and `WebSite` (the latter with a `SearchAction`, which can earn a sitelinks
search box). Record pages add their own: `Article` on a blog post, `Event` on a
webinar (online attendance mode, virtual location, speaker as `performer`, and
`Offer`s built from `offerings.price_cents` — the one source of price), and
`FAQPage` on any page with a visible FAQ block. Every value comes from a real
field; anything missing is **omitted, not guessed** (brief §15 — schema must
state only what the page shows). JSON is encoded with `JSON_HEX_TAG`, so a value
can never break out of the `<script>` block.

**Breadcrumbs** (`partials/breadcrumbs`): a controller builds one `$crumbs`
array and passes it both to the view and to `Schema::breadcrumbs()`, so the
visible trail and its `BreadcrumbList` schema can never disagree.

**Analytics & integrations** (Settings → Integrations, `App\Support\Integrations`):
GA4 (`G-…`), GTM (`GTM-…`), Google Search Console verification, and Tawk.to live
chat. Each ID is format-validated. **GA4 and GTM are mutually exclusive** — if
GTM is set, the direct GA4 tag is suppressed so pageviews aren't counted twice.
No secret ever lives here; these are all public client-side IDs.

**FAQ block**: a page-builder section (`type: faq`) with question/answer rows.
The schema is built from the same visible rows the page renders — a hidden block
emits none, and empty rows are skipped.

**SEO health dashboard** (Content → SEO health, `App\Filament\Pages\SeoHealth`
over `App\Seo\SeoAudit`): audits every public webinar, article and page for
missing/thin/over-long meta descriptions, over-long titles, duplicate titles,
pages hidden from search, a missing focus keyword, and images with no ALT text.
Each finding links straight to the record's edit screen.

**Images**: faculty photos and the home webinar card carry explicit dimensions
and `loading="lazy"`. WebP/responsive `srcset` is deferred to P9's performance
pass (GD's WebP support is confirmed on the host); the site has few raw `<img>`
tags, so the win there is small until then.

Run `php artisan migrate` for the `redirects` table.

Category pages are SEO-editable in the admin now (the trait is on `Industry` and
`PostCategory`), but they have no dedicated public URL yet — a category is a
`?category=` filter on `/blog/`, which canonicalises to `/blog/`. Wiring the
editable fields to real category URLs waits on those URLs existing.
