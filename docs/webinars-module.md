# Webinars module — analysis and build plan

Derived from `design-ui/Webinar Page.pdf` and the live site's cart, agreed with
Tarun on 2026-09-04. Amends CLAUDE.md §5.3, which predates both.

Read §2 before writing any migration. The five findings there change the schema,
and four of them are invisible from the brief alone.

---

## 1. What the page is

| Region | Contents |
|---|---|
| Hero | Full-bleed **banner image**, type + industry badges, title, summary, a four-cell info box (date/time, duration, speaker, credits), two CTAs |
| Purchase panel | Seven options, each with an info tooltip, a price and a quantity selector; a CTA disabled until something is chosen; a trust row |
| Tab bar | Overview · Session Highlights · Why Attend · Who Should Attend · Speaker(s) — anchors into the stacked sections below |
| Body | Those five sections, then a Q&A note and an **attendee instructions** callout |
| Rail | Similar Webinars — four cards, same component as the homepage |

Breadcrumb is `Home › Live Webinars › Human Resources › Title`, so the catalogue
routes by type and by industry.

The five content sections map onto columns §5.3 already has — `summary`,
`overview`, `session_highlights`, `why_attend`, `who_should_attend`. Nothing new
is needed for the copy.

---

## 2. Five things §5.3 cannot express

### 2.1 An offering sells more than one format

The panel sells seven options; **four are bundles**.

| Option | Price | Grants |
|---|---|---|
| Live Webinar | $199 | live |
| Recorded | $199 | recorded |
| Transcript (PDF) | $179 | transcript |
| Recorded + Transcript | $279 | recorded, transcript |
| Live + Transcript | $279 | live, transcript |
| Live + Recorded + Transcript | $279 | live, recorded, transcript |
| Download | $279 | download |

§5.3 gives `offerings` a single `format` enum, so four of the seven cannot be
represented at all.

This is not cosmetic. §5.2 keys the entitlement ledger on
`(user, webinar, format)`, so the $279 three-format bundle must write **three**
entitlement rows. One format per offering means the customer pays $279 and
receives one format — the exact class of defect §14 exists to prevent.

**Resolved:** an `offering_format` pivot. `GrantEntitlements` loops an offering's
formats instead of reading one. Every access check, membership quota and refund
revocation downstream is unchanged.

> **Raise with the client.** Live + Recorded + Transcript is priced at $279 —
> identical to both two-format bundles, against $577 bought separately. Nobody
> buys a two-format bundle when three cost the same. Either a placeholder or a
> pricing error.

### 2.2 Add-on pricing

The cart offers **Add Recorded $55**, **Add E-Transcript $55**, **Add Download
$55** against $199 / $179 / $279 standalone. A format has a second, lower price
that applies only when the webinar is already in the cart.

**Resolved:** `offerings.addon_price_cents`, nullable — null means the format is
not offered as an add-on. The cart uses it when another offering for the same
webinar is present. Price is still resolved server-side from `offerings`, so
§14 #1 holds: nothing about the amount comes from the request.

### 2.3 Banner image

§5.3 has one `image`, used for the card thumbnail. The hero needs a separate
wide banner.

**Resolved:** two media-library *references*, `card_media_id` and
`banner_media_id`, picked through the media manager (docs/media-manager.md §3.1)
rather than uploaded per webinar. A reference means replacing the file in the
library updates every webinar that picked it, and `media_usages` lets the delete
guard refuse to remove a file a webinar still points at (Webinar implements
`ReferencesMedia` / `RecordsMediaUsage`). `Webinar::cardImageUrl()` /
`bannerImageUrl()` resolve the URL; the hero prefers the background then the
card, the rail the card then the background.

### 2.4 Attendee instructions

The "Important Notice for Our Live Attendees" callout — join-kit timing for live
registrants, delivery windows for recorded and transcript buyers.

**Resolved:** site-wide default in `settings`, with a nullable
`webinars.attendee_instructions` override for sessions needing specific joining
detail. The override wins when non-empty.

### 2.5 Purchase option tooltips

Every row in the panel carries an info icon explaining what is being bought. It
does real work: the difference between *Recorded*, *Transcript* and *Download*
is not obvious from the label, and that ambiguity is what drives refunds.

**Resolved:** four `settings` entries, one per format, site-wide. Bundles
compose their description by joining their constituent formats' text — which
falls out of the pivot in §2.1, so no per-offering column is needed.

**Accessibility:** hover-only tooltips fail WCAG 2.1 §1.4.13 — unreachable by
keyboard, and on touch a tap either does nothing or fires the row. §7 P9 audits
for AA, so this must be a focusable `<button>` with `aria-describedby`, openable
on focus as well as hover, dismissible with Escape, and staying open while the
pointer travels onto it. Same cost built correctly the first time.

---

## 3. Webinar type

`webinars.type` as a backed enum. Not derivable from `status`, which is
lifecycle, and deriving it from offerings would be fragile.

| Type | `scheduled_at` | May sell |
|---|---|---|
| `live` | required | live, recorded, transcript, download |
| `recorded` | none | recorded, transcript, download |

It drives whether the date field is required, which public listing the webinar
appears in, the card badge, and one real validation: **a `recorded` webinar must
not sell the `live` format.** Without that check you can publish something that
takes money for a session that will never happen.

**Packages stay a separate table**, per §5.3 and §6. A package has no speaker,
date, duration or credits of its own — it inherits them from its children — so
folding it into `webinars` would leave those columns meaningless on package rows
and put `where type != 'package'` on every catalogue query. Users still browse
**Live / Recorded / Packages** as three tabs; only the schema knows there are two
tables behind them.

---

## 4. Schema delta against §5.3

```
webinars          + type(enum: live|recorded)
                  + attendee_instructions TEXT NULL
                  media collections: card, banner

offerings         + addon_price_cents INT NULL
                  - format            (moves to the pivot)

offering_format   offering_id, format(enum)          NEW
                  UNIQUE (offering_id, format)

settings          formats.live.description           NEW rows
                  formats.recorded.description
                  formats.transcript.description
                  formats.download.description
                  webinars.default_instructions
```

Unchanged and already sufficient: `webinar_assets` (recorded URL, transcript
PDF, downloadable materials, each with `available_from` and `is_active`),
`webinar_accreditation` with `credits`, `speakers`, `industries`,
`accreditations`, `packages`, `package_webinar`.

---

## 5. Build plan

### Schema — 3 days
1. Enums: `WebinarType`, `WebinarStatus`, `Format`
2. Migrations: speakers, industries, accreditations, webinars, webinar_assets,
   webinar_accreditation, offerings, offering_format
3. Models — relationships, casts, medialibrary collections, scopes
4. Factories and a realistic seeder (§5.9)

### Admin — 5 days
5. Speakers, Industries, Accreditations resources
6. Webinars resource — list with status tabs, type/industry/speaker/accreditation
   filters, and the two that find broken records: *has no offering*, *has no join
   link*
7. Tabbed form — Details, Content, Schedule (hidden for `recorded`), SEO
8. Offerings relation manager — multi-format select, price, add-on price, SKU,
   seats per unit
9. Assets relation manager — recorded URL, transcript PDF, materials, release
   dates
10. Accreditation credits relation manager
11. Publish gate, duplicate action, slug → redirect
12. Settings: format descriptions + default instructions

### Public — 4 days
13. Route, controller, breadcrumb
14. Banner hero and info box
15. Purchase panel — quantities, bundle labels, accessible tooltips, CTA
    disabled until a selection exists
16. Anchored tab bar and the five content sections
17. Speaker card, both callouts, Similar Webinars rail

### Tests — 2 days
18. One policy test per role; publish-gate validation
19. **Bundle fan-out** — a three-format offering produces exactly three
    entitlement rows, once
20. Add-on price applies only with a qualifying cart item
21. A `recorded` webinar cannot be published with a `live` offering

**~14 days**, against §6's 2.0 weeks for the module. The overrun is items 2, 8
and 19 — the bundle work the original schema did not anticipate.

---

## 6. Guardrails carried from CLAUDE.md

- **Publishing is a gated action, not a status dropdown.** `draft → scheduled`
  requires a speaker, a date if `live`, at least one active offering and at least
  one accreditation. Refuse with a reason rather than saving a broken record: an
  offering-less webinar renders in the catalogue and fails at add-to-cart, and
  `offerings` is "the ONLY place a price is ever read from" (§5.3).
- **Timezone.** Store UTC, enter in the webinar's timezone, and render a live
  preview under the field — `26 Sep 2026 · 09:00 AM PST` — so whoever types it
  sees what the customer will see. A wrong time on a live CEU session is a refund
  and a compliance problem.
- **Slug changes write a redirect.** §3 has the business living on SEO. Changing
  a slug on a non-draft webinar silently breaks every inbound link, so the old
  path goes into `redirects` (§5.6) automatically.
- **Eager-load the list.** Speaker and industry per row. `Model::shouldBeStrict()`
  is already on outside production, so lazy loading throws rather than quietly
  costing 50 queries — but the resource's query needs `->with()` stated.
- **Duplicate action.** Webinars repeat: same topic, new date, four times a year.
  Cloning content, offerings and credits while clearing the date is what decides
  whether the client enjoys using this.

---

## 7. Still open

- The $279 three-format price (§2.1) — confirm with the client.
- Should publishing need its own permission, separate from `update_webinar`? The
  Content Editor currently holds `update_webinar` and could put a webinar live.
- Should Offerings get a flat "Pricing" screen across the whole catalogue? It is
  the only place prices live, so one sortable view is useful for auditing — at
  the cost of a 23rd sidebar item.
