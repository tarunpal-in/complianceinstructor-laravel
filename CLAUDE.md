# CEU Training Platform — Project Brief & Working Agreement

> **Rename this file to `CLAUDE.md` in the new project root.** Claude Code loads `CLAUDE.md`
> automatically at the start of every session, which is the whole point: no future session
> should have to rediscover any of this.
>
> **Status:** pre-build, nothing written yet · **Authored:** 2026-08-15, from a full audit of
> the predecessor system (CourseMinistry).

---

## 0. How to use this file

If you are an AI assistant picking this project up, read the whole file before writing code.
It is the single source of truth for what we are building, what was already decided, and
which mistakes are forbidden.

Three sections carry hard constraints rather than suggestions:

- **§2 Locked decisions** — already agreed with the client. Do not relitigate them. If you
  think one is wrong, say so once, then proceed.
- **§4 Ground rules** — architectural constraints. Code violating these gets rewritten.
- **§14 Anti-patterns** — real defects from the predecessor that cost real money. Each is
  banned by name.

When asked for a feature, locate it in §6 (module) and §7 (phase). If it is in neither, it is
new scope: say so and quote it against §13 rather than absorbing it silently.

---

## 1. What we are building

A **continuing-education (CEU) training platform** selling professional-development webinars
and memberships to a business audience.

### Domain primer

**Webinars** are the core content: a speaker, an industry, a scheduled date, a duration, and
**continuing-education credits** from accrediting bodies — HRCI, SHRM, CPE, APA. The credits
are what customers are really buying; they need them to maintain a professional certification.
That makes accreditation rules a compliance concern, not a feature preference.

Each webinar sells in several **delivery formats**, priced independently:

| Format | What the customer gets |
|---|---|
| `live` | Attends the scheduled session; join link released before start |
| `recorded` | On-demand video afterwards |
| `transcript` | Written e-transcript of the session |
| `download` | Downloadable course materials |

**Packages** bundle several webinars at a combined price. **Memberships** are subscriptions
granting an allowance — a count, or unlimited — of a given format per term.

A customer therefore reaches content by one of three routes: buying a single webinar format,
buying a package containing it, or redeeming a membership allowance. **All three must
converge on one access record.** See §5.2 — the most important design decision in the project.

### Audience and revenue

B2B professional buyers, frequently purchasing multiple seats for a team, often invoiced
rather than paying by card. Revenue mixes one-off purchases with recurring memberships.

### Non-goals for v1

No mobile app. No public API. No multi-tenancy. No live video hosting — we link out to
Zoom/GoToWebinar rather than streaming ourselves.

---

## 2. Locked decisions

Agreed with the client on 2026-08-11.

| Decision | Choice |
|---|---|
| **Approach** | Fresh build on Laravel 12. Port the *domain model* from the predecessor, never its code |
| **Scope** | Full feature parity **plus** six improvements (§8) |
| **Team** | One full-stack developer |
| **Effort** | 32 focused weeks (~8 months calendar at 1.25×) |

### The two leverage decisions

Parity + improvements, solo, built conventionally is roughly **50 weeks**. It lands at 32
only because of these. **Both are load-bearing — reverting either silently adds months.**

**1. Filament owns the entire admin panel.**
In the predecessor, 190 of 278 named routes were admin routes: twelve CRUD modules, sixteen
SEO editors, a 1,470-line home-page controller. Hand-building that is three to four months.
A Filament resource gives a full CRUD screen — filters, search, bulk actions, validation,
uploads — in roughly 80 lines. **Worth ~10 weeks.**
→ *Default to a Filament resource. Never hand-write an admin controller plus Blade form.*

**2. One page builder replaces ~35 CMS models.**
The predecessor had a separate model, table, controller, route set and Blade form for every
page section: `HomeBanner`, `IndexIcon`, `ChooseBlock`, `AboutBlock`, `TrainingCms`,
`FoodSafetyCms`, thirty more. We use `pages` + `page_sections` with Filament's Builder field.
A new section type costs one Blade partial, not a full vertical slice. **Worth ~5 weeks**, and
it removes the reason the predecessor needed sixteen near-identical SEO screens.
→ *Never create a model for a page section.*

---

## 3. Stack

Every entry either eliminates work or removes a class of bug a solo developer cannot afford
to debug alone. **Verify current stable versions at install time** — notes reflect 2026-08.

### Core

| Layer | Choice | Why |
|---|---|---|
| Framework | **Laravel 12**, PHP 8.3+ | Predecessor was Laravel 11, so idioms port directly |
| Admin | **Filament v4** | The biggest single lever — see §2 |
| Front-end | **Blade + Livewire 3 + Alpine** | Server-rendered for SEO, which this business lives on. Livewire only where state is real: cart, checkout, catalogue filters, quiz runner |
| Styling | **Tailwind 4 + Vite** | Actually wired up this time. The predecessor installed Tailwind, never used it, and shipped 124 hand-maintained static asset files |
| Database | **MySQL 8** | Matches the client's hosting |
| Cache / session / queue | **Redis** | The predecessor ran all three on MySQL. Redis removes the write contention that appears on the first webinar-launch spike |
| Queue runner | **Horizon + Supervisor** | PDFs, mail, certificates, drip campaigns off the request cycle, with failure visibility for free |

### Packages

| Package | Purpose |
|---|---|
| `filament/filament` | Admin panel (v4) |
| `laravel/cashier` | Stripe subscriptions — proration, dunning, SCA. Months you do not write |
| `paypal/paypal-server-sdk` | PayPal orders; this audience expects it |
| `laravel/fortify` | Auth scaffolding: signed single-use reset tokens, throttling, 2FA. Eliminates the exact vulnerability class found in the predecessor |
| `spatie/laravel-permission` | Roles & permissions (improvement #3) |
| `spatie/laravel-medialibrary` | Media, conversions, responsive images. Replaces 926 timestamp-prefixed files in `public/uploads` |
| `spatie/laravel-pdf` | Invoices, certificates (Browsershot, real CSS). Keep `dompdf` as fallback if headless Chrome is unavailable on the host |
| `spatie/laravel-activitylog` | Audit trail — the predecessor had none |
| `spatie/laravel-sitemap` | Generated sitemap. The predecessor installed this and never called it |
| `spatie/laravel-backup` | Off-site encrypted backups |
| `laravel/scout` | Search; MySQL driver suffices at this catalogue size |
| `laravel/horizon` | Queue dashboard |
| `maatwebsite/excel` | CSV/XLSX exports — carried over, it worked well |

**Dev:** `pestphp/pest` · `larastan/larastan` (level 6) · `laravel/pint` · `barryvdh/laravel-debugbar`

### Deliberately excluded

- **Any headless/SPA split** — doubles the surface for one person.
- **A second toast library** — the predecessor shipped two overlapping ones.
- **Anything needing a second developer to maintain.**

### Bootstrap

```bash
composer create-project laravel/laravel ceu-platform
cd ceu-platform

composer require filament/filament laravel/cashier laravel/fortify laravel/horizon \
  laravel/scout spatie/laravel-permission spatie/laravel-medialibrary spatie/laravel-pdf \
  spatie/laravel-activitylog spatie/laravel-sitemap spatie/laravel-backup \
  maatwebsite/excel paypal/paypal-server-sdk

composer require --dev pestphp/pest larastan/larastan laravel/pint barryvdh/laravel-debugbar

php artisan filament:install --panels
php artisan vendor:publish --tag=cashier-migrations
npm install && npm install -D tailwindcss @tailwindcss/vite
```

---

## 4. Ground rules

1. **Every table has a migration, a factory and a seeder.**
   The predecessor had ~25 live tables with no migration and 43 columns missing from tables
   it did create — its schema could not be rebuilt from source at all.
   `migrate:fresh --seed` must always produce a working, demoable app. No exceptions.

2. **Controllers route; Actions do.**
   Single-purpose invokable Actions: `PlaceOrder`, `CapturePayment`, `GrantEntitlements`,
   `IssueCertificate`, `RedeemMembershipAllowance`. Nothing touching money lives in a
   controller. The predecessor's `CheckoutController` was 1,300 lines with a 330-line `store()`.

3. **Form Requests for all input.** No `$request->all()` reaching a model, ever.

4. **Policies for all authorization.** Never a raw `role_id === 1` comparison in middleware.

5. **Enums for every status** — order, payment, delivery format, subscription state,
   entitlement source. PHP 8 backed enums, never loose strings. The predecessor lost an entire
   membership quota check to `'E_transcript'` vs `'E_transcripts'`.

6. **Money is integer cents.** Columns end `_cents`; cast at the model boundary. Never float
   arithmetic on a price.

7. **Anything slow is queued** — PDFs, mail, exports, certificates, webhook side effects.

8. **Replaced code is deleted, not renamed `old_`.** The predecessor carried two full
   front-end themes, two user dashboards and three near-complete copies of itself.

---

## 5. Data model

~48 tables. The predecessor's domain was sound; its schema was not. Three fixes do most of the
work: a unified entitlements ledger, a real package pivot, and one order header instead of two.

### 5.1 Conventions

- `id` bigint auto-increment; UUIDs only where a value is public (order numbers, certificate
  serials).
- Money: `*_cents` integer plus a `currency` char(3).
- Timestamps `*_at`. Soft-delete anything financial or content-bearing.
- Enums are PHP backed enums cast on the model, stored as short strings.
- Foreign keys use real constraints. The predecessor had none.

### 5.2 Access — the central design decision

In the predecessor, *"can this person watch this recording?"* meant joining `user_purchases`,
then date-checking `purchase_plans`, then reading **seventeen** boolean and counter columns on
`memberships` — duplicated across four dashboard controllers, and the most likely place for a
costly access bug.

**Replace all of it with one append-only grant ledger.** Orders write to it, subscriptions
write to it, admins write to it for comps and corrections. Every access check becomes one
indexed query, and a refund revokes access by writing `revoked_at` rather than unwinding four
related records.

```
orders ─────────┐
subscriptions ──┼──▶  entitlements  ──▶  can(user, webinar, format)?
admin grant ────┘
```

```
entitlements
  id, user_id, webinar_id, format(enum),
  source_type(enum: order|subscription|manual), source_id,
  granted_at, expires_at NULL, revoked_at NULL, note
  INDEX (user_id, webinar_id, format), INDEX (expires_at)
```

One helper — `Entitlement::grants($user, $webinar, $format)` — is the **only** way any part of
the app asks about access. No controller ever queries orders or subscriptions to decide what
someone may see.

### 5.3 Catalogue

```
speakers          id, name, slug, title, bio, photo, linkedin_url, is_active
industries        id, name, slug, image, description, is_active, sort_order
accreditations    id, code(HRCI|SHRM|CPE|APA), name, logo, description

webinars          id, title, slug, summary, description, overview, background,
                  who_should_attend, why_attend, session_highlights,
                  speaker_id, industry_id, scheduled_at, timezone,
                  duration_minutes, seats_total NULL, image,
                  status(enum: draft|scheduled|live|completed|archived)

webinar_accreditation  webinar_id, accreditation_id, credits decimal(4,2)

webinar_assets    id, webinar_id, format(enum), url NULL, disk_path NULL,
                  available_from NULL, is_active
                  -- replaces 8 link/file columns on the predecessor's webinars table

packages          id, title, slug, summary, description, image, status
package_webinar   package_id, webinar_id, sort_order
                  -- replaces a JSON array decoded independently in 4 places

offerings         id, offerable_type, offerable_id,      -- webinar OR package
                  format(enum), sku, price_cents, compare_at_cents NULL,
                  seats_per_unit, is_active
                  -- the sellable SKU; the ONLY place a price is ever read from
```

### 5.4 Commerce

```
carts             id, user_id NULL, session_token, currency, expires_at
cart_items        id, cart_id, offering_id, quantity, unit_price_cents
                  -- display snapshot only; ALWAYS re-derived at checkout

orders            id, number UNIQUE, user_id,
                  status(enum: pending|paid|partially_refunded|refunded|cancelled|failed),
                  subtotal_cents, discount_cents, tax_cents, total_cents, currency,
                  coupon_id NULL, billing_snapshot JSON, placed_at
                  -- billing_snapshot is frozen: historic invoices must never mutate

order_items       id, order_id, offering_id, webinar_id, format, description,
                  quantity, unit_price_cents, line_total_cents
order_attendees   id, order_item_id, name, email, entitlement_id NULL

payments          id, order_id, gateway(enum: stripe|paypal),
                  gateway_payment_id UNIQUE,   -- this UNIQUE is the idempotency guard
                  status, amount_cents, currency, raw_response JSON, processed_at
refunds           id, payment_id, gateway_refund_id, amount_cents, reason,
                  status, processed_at

coupons           id, code UNIQUE, type(enum: percent|fixed), value, currency,
                  starts_at, ends_at, max_redemptions NULL, max_per_user NULL,
                  minimum_spend_cents NULL, scope_type NULL, scope_id NULL, is_active
coupon_redemptions  id, coupon_id, user_id, order_id, amount_cents, redeemed_at
                    UNIQUE (coupon_id, order_id)

tax_rates         id, country, region NULL, rate, applies_to, is_active
```

**`orders` is the order header and carries its own number.** The predecessor split this across
`orders` (actually order *lines*), `invoices` (actually the order) and `invoice_items` (a join
table duplicating price and quantity) — which is why every admin screen read from the wrong
model.

### 5.5 Membership

```
membership_plans  id, name, slug, description, price_cents,
                  billing_period(enum: month|quarter|year|one_time),
                  duration_months, stripe_price_id, is_active, sort_order

plan_entitlements id, membership_plan_id, format(enum),
                  quantity NULL,             -- NULL = unlimited
                  window(enum: term|month)
                  -- replaces 17 boolean/counter columns with rows. Adding a
                  -- "4 live + unlimited transcripts" tier stops being a migration.

subscriptions     id, user_id, membership_plan_id, status, gateway,
                  gateway_subscription_id, starts_at, ends_at, cancelled_at, price_cents

subscription_usage  id, subscription_id, format, used_count, period_start, period_end
                    UNIQUE (subscription_id, format, period_start)
                    -- allowances reset per period. The predecessor summed all-time
                    -- purchases, so allowances never reset on renewal.
```

### 5.6 Content

```
pages             id, title, slug, status, published_at, layout
page_sections     id, page_id, type, data JSON, sort_order, is_visible
                  -- replaces ~35 CMS models; edited via Filament Builder
seo_meta          id, seoable_type, seoable_id, title, description, keywords,
                  og_image, canonical, robots       -- replaces 16 hardcoded page types
navigation_items  id, menu, label, url, parent_id, sort_order
redirects         id, from_path, to_path, status_code
settings          id, group, key, value JSON, type
media             -- spatie/laravel-medialibrary
```

### 5.7 Engagement

```
posts · post_categories · tags · taggables
forum_categories · forum_threads · forum_replies · forum_reactions
quizzes · quiz_questions · question_options · quiz_attempts · attempt_answers · leads
subscribers · inquiries
```

### 5.8 Platform

```
users             -- NO role column; roles come from spatie
roles · permissions · model_has_roles · role_has_permissions
attendance        id, user_id, webinar_id, joined_at, left_at,
                  duration_minutes, watch_percent
certificates      id, user_id, webinar_id, accreditation_id, serial UNIQUE,
                  credits, issued_at, revoked_at NULL, pdf_path
email_sequences · email_steps · email_sends
activity_log · jobs · failed_jobs · job_batches
```

### 5.9 Seed data is a deliverable

Working alone, the seeded database **is** the QA environment and the client-review
environment. Budget factories plus a realistic seeder from Phase 1: 30 webinars, 8 speakers,
5 industries, 4 plans, 60 orders spread across a year, a few mid-term memberships. Every later
phase gets faster because of it.

---

## 6. Module inventory

Fourteen modules. Effort assumes Filament for admin and a shared Blade component library for
the front-end.

| Module | Admin surface | Public surface | Wks |
|---|---|---|---|
| Webinars | Resource + assets & offerings relation managers, accreditation credits, scheduling | Catalogue, filters, detail, add-to-cart | 2.0 |
| Packages | Resource + webinar picker, bundle pricing | Listing & detail | 0.8 |
| Speakers | Resource, bio, socials, slug | Index & profile | 0.5 |
| Industries | Resource + landing content | Industry landing pages | 0.5 |
| Cart & checkout | Abandoned-cart view | Livewire cart, guest checkout, attendee capture | 2.5 |
| Payments | Payment log, refund action, webhook monitor | Stripe Elements + PayPal flow, return handling | 2.5 |
| Orders | Resource, status workflow, manual order, PDF & Excel export | Order history, invoice download | 1.5 |
| Coupons | Resource + usage caps, redemption log | Apply at checkout | 0.8 |
| Memberships | Plan builder, entitlement rows, subscriber list | Pricing page, subscribe, manage plan | 2.5 |
| Learner dashboard | Impersonate, manual grant | Upcoming, recordings, transcripts, downloads, profile | 2.0 |
| Page builder & SEO | Block builder, navigation, settings, polymorphic SEO | All marketing pages, sitemap, schema.org | 3.0 |
| Blog | Resource, categories, editor, media | Index, filters, article, related | 1.0 |
| Forum | Categories, moderation queue | Threads, replies, reactions | 1.5 |
| Quiz & leads | Quiz builder, questions, scoring, lead export | Public quiz by slug, scoring, result page | 1.5 |

Module total: **22.6 weeks.** The remaining 9.4 are foundation, the six improvements,
hardening and launch.

---

## 7. Phase plan

Ten phases, 32 weeks, ordered so the money path is provably working by **week 13** and
everything after is additive.

| # | Phase | Weeks |
|---|---|---|
| P0 | Discovery & setup | 1–2 |
| P1 | Foundation | 3–5 |
| P2 | Catalogue | 6–8 |
| P3 | **Commerce** | 9–13 |
| P4 | Memberships | 14–16 |
| P5 | Learner dashboard | 17–19 |
| P6 | CMS & SEO | 20–22 |
| P7 | Engagement | 23–26 |
| P8 | The six improvements | 27–29 |
| P9 | Harden & launch | 30–32 |

**P0 · Discovery & setup (W1–2).** Commercial workshop: formats sold, accrediting bodies,
how credits are awarded, refund policy, tax handling. Lock brand and design direction. Repo,
CI, staging, Stripe and PayPal sandbox accounts.
*Exit:* signed-off sitemap, offering matrix, empty app deployed to staging, CI green.

**P1 · Foundation (W3–5).** Full schema as migrations, models with relationships and enums,
factories and the realistic seeder. Fortify auth with verification, throttling and admin 2FA.
Roles and permissions. Filament panel installed, branded, navigation skeleton. Media library,
settings, activity log.
*Exit:* `migrate:fresh --seed` gives a populated app; log in as admin, editor and customer and
see correctly scoped navigation.

**P2 · Catalogue (W6–8).** Webinars with assets and offerings, packages with a real pivot,
speakers, industries, accreditations and credit assignment. Filament resources for all of it.
Public catalogue with Livewire filtering (format, industry, date, accreditation), detail page,
speaker profiles.
*Exit:* the client can enter their real catalogue and it renders publicly.

**P3 · Commerce (W9–13).** The critical path. Database cart, guest and authenticated checkout,
attendee capture for multi-seat orders, coupon application with caps, tax and totals. Stripe
via Cashier and PayPal as second gateway, both behind a single `PaymentGateway` contract.
Idempotent webhook handling. Order creation, entitlement granting, invoice numbering, queued
PDF invoice and confirmation mail. Refunds that revoke entitlements.

> **Hard gate — do not leave P3** until a Pest suite proves: a successful capture grants
> exactly the right entitlements; a duplicate webhook does not double-grant; a failed capture
> grants nothing; a refund revokes access. This is the only place in the build where a bug
> costs real money.

*Exit:* end-to-end purchase on both gateways in sandbox, tests green.

**P4 · Memberships (W14–16).** Plans with entitlement rows, subscription lifecycle through
Cashier (upgrade, downgrade, cancel, dunning), usage metering against quotas, renewal and
expiry jobs. Public pricing page, self-service plan management.
*Exit:* a member can subscribe, consume quota, hit a limit, upgrade and cancel — each reflected
in `entitlements` within one job cycle.

**P5 · Learner dashboard (W17–19).** Upcoming sessions with join links released on schedule,
recordings, transcripts, downloads, order history and invoices, profile and password,
notifications. All gated through the entitlement check, with signed expiring URLs for files.
*Exit:* a customer with mixed one-off and membership access sees exactly what they are owed and
nothing else.

**P6 · CMS & SEO (W20–22).** Page builder with the block library — hero, feature grid,
testimonial slider, logo wall, stat band, FAQ accordion, CTA, rich text, webinar carousel.
Navigation manager, global settings, polymorphic SEO with Open Graph and schema.org, generated
sitemap, redirect manager. Then build the marketing pages: home, about, training landings,
membership, contact, FAQ, four policy pages.
*Exit:* the client edits every marketing page unaided, without a developer touching Blade.

**P7 · Engagement (W23–26).** Blog with categories, tags and editor. Forum with threads,
replies, reactions, moderation. Quiz engine: builder, public quiz by slug, scoring against
thresholds, result pages, lead capture and export. Newsletter subscribe/unsubscribe. Contact
and inquiry handling.
*Exit:* all secondary modules live and administrable.

**P8 · The six improvements (W27–29).** Certificates, drip email automation and reporting —
the three needing real build time. Stripe, coupon caps and roles were folded into earlier
phases by design. See §8.

**P9 · Harden & launch (W30–32).** Security pass against §9, load test on catalogue and
checkout, Lighthouse and Core Web Vitals, WCAG AA keyboard and contrast audit, cross-browser
check, backup and restore rehearsal, monitoring and error tracking, content load, 301 map from
any legacy URLs, go-live, two-week hypercare.
*Exit:* live, monitored, backed up and restorable — proven by an actual restore, not an
assumption.

---

## 8. The six improvements

Three are structural and were built into earlier phases; three need dedicated time in P8.

| Improvement | Specification | Phase |
|---|---|---|
| **Stripe + PayPal** | Single `PaymentGateway` contract, two drivers. Stripe via Cashier for cards, wallets, SCA and all subscription billing; PayPal for one-off orders. Gateway selectable per checkout; both write to the same `payments` table so reporting never branches | P3 |
| **Coupon caps** | `max_redemptions`, `max_per_user`, `starts_at`, `minimum_spend_cents`, and scoping to industry, format or specific webinars. Every use writes a `coupon_redemptions` row, checked **inside the order transaction** so concurrent checkouts cannot both take the last redemption | P3 |
| **Admin roles** | Super Admin, Content Editor, Finance, Support over ~40 named permissions. Filament navigation, resource pages and individual actions all gate on permissions. Every mutation written to `activity_log` with the actor | P1 |
| **CEU certificates** | Issued automatically when the completion rule is met — attendance threshold for live, watch-through for recorded, or a passing quiz score where the accreditor requires assessment. Branded PDF carrying learner name, webinar title, credit hours, accrediting body, issue date and a unique serial. Queued, stored on a private disk, downloadable from the dashboard, with a public verification URL by serial. Admin can revoke and reissue | P8 |
| **Email automation** | Event-driven sequences on a scheduled dispatcher: registration confirmation; reminders at 7d / 1d / 1h; post-session recording-ready and certificate-ready; abandoned cart at 4h and 24h; membership renewal and dunning; win-back at 90 days idle. Templates editable in admin, per-sequence unsubscribe, send log | P8 |
| **Reporting** | Revenue by period, format, industry, speaker; MRR, churn and cohort retention; top webinars by revenue and attendance; coupon performance and discount cost; funnel from view → cart → purchase; attendance and completion rates; lead source attribution. Filament widgets over pre-aggregated daily tables, CSV export on every panel | P8 |

---

## 9. Security baseline

The predecessor audit surfaced findings worth treating as a permanent regression checklist.
Each maps to a rule making recurrence structurally impossible.

| Found in predecessor | Sev | Structural prevention |
|---|---|---|
| Unauthenticated `POST /admin/update/password` — supply an admin email and a new password, take the account | Critical | Fortify owns all password resets: signed, expiring, single-use tokens with throttling. No hand-rolled reset endpoint exists to get wrong |
| Unauthenticated bulk user import — routes declared outside the middleware group | Critical | Filament applies middleware at **panel** level, not per route. No route file exists in which an admin page can be accidentally left unprotected |
| Free-order bypass — posting `status=place_order` completed any cart at no cost | Critical | Payment and fulfilment are one Action inside one DB transaction. Order status is derived from a `payments` row, never from request input |
| Charge amount taken from a client `final_amount` field | Critical | Totals are always recomputed server-side from `offerings.price_cents`. Client-supplied money values are ignored entirely |
| Coupons validated only in JavaScript, all codes embedded in page source | Critical | Coupon validation is server-side only. Never serialise the coupon table into a view |
| IDOR — any user could view, download or email any invoice | Critical | Policies on every model; ownership scoping asserted by test |
| Arbitrary file read / SSRF via an unvalidated `?url=` download proxy | Critical | No URL proxies. Files served by entitlement ID through signed, expiring URLs on a private disk |
| Live credentials in a committed `.env`, `APP_DEBUG=true` | Critical | `.env` git-ignored from the first commit; secrets injected by the deploy pipeline; CI fails on a committed secret; production config check asserts debug is off |
| Public `GET /clear` cache-flush route | High | No maintenance routes. Artisan runs through the deploy pipeline only |
| `dd($e)` in the admin login catch block | High | Larastan and Pint in CI reject debug helpers; errors go to log and Sentry, never the response |

### Standing requirements

- All file access through signed, expiring URLs on a private disk — never a guessable path.
- Webhooks signature-verified and **idempotent by gateway event ID**.
- Rate limiting on login, registration, reset, coupon application and checkout.
- Prices, totals and entitlements resolved server-side; never trusted from the request.
- Policy coverage asserted by test for every model exposed in the admin.
- Automated dependency scanning, plus off-site encrypted backups with a rehearsed restore.

---

## 10. Testing

Solo, you cannot test everything. Test where a bug costs money, access or trust; lean on
static analysis for the rest.

| Area | Depth | Rationale |
|---|---|---|
| Payments & orders | **Exhaustive** | Capture, decline, duplicate webhook, partial refund, coupon race, tax and totals — every path asserted against the resulting entitlements |
| Entitlements | **Exhaustive** | Grant, expiry, revoke, quota exhaustion, overlapping sources. Wrong answers either give content away or lock out paying customers |
| Subscriptions | **Exhaustive** | Renewal, upgrade proration, cancellation timing, failed-payment dunning |
| Authorization | **Exhaustive** | One test per policy per role. Cheap to write, and the failure mode is a breach |
| Certificates | Targeted | Issue conditions and serial uniqueness. PDF appearance verified by eye |
| CRUD & CMS | Smoke | Filament is tested upstream; one smoke test per resource confirms it loads and saves |
| Front-end pages | Smoke | Assert 200 and key content on every public route. Catches broken views after refactors for almost nothing |

Larastan level 6 and Pint run in CI on every push, standing in for the code review a solo
developer does not get.

---

## 11. Infrastructure

A VPS provisioned properly. The predecessor ran on cPanel with a root `.htaccess` rewriting
into `/public`, leaving the whole project tree — including two full legacy WordPress installs
— inside the web root.

| Concern | Setup |
|---|---|
| Host | Ubuntu 24.04 VPS, 4 vCPU / 8 GB to start. Nginx document root pointed at `public/` **only** |
| Runtime | PHP 8.3 FPM with OPcache and JIT, Redis, MySQL 8, Supervisor for Horizon, headless Chrome for PDFs |
| Environments | Local · staging · production. Staging mirrors production and carries seeded data for client review |
| Deploy | GitHub Actions → zero-downtime atomic release. Pipeline runs Pint, Larastan, Pest, then builds assets, migrates and warms caches. Rollback is a symlink switch |
| Media & CDN | S3-compatible object storage for uploads and generated PDFs; Cloudflare for caching, TLS and WAF |
| Mail | Transactional provider (Postmark or SES) with SPF, DKIM and DMARC — not shared cPanel SMTP |
| Observability | Sentry for exceptions, Horizon for queues, uptime monitoring on **checkout specifically**, weekly automated restore verification |
| Backups | `spatie/laravel-backup` nightly to separate off-site storage, 30-day retention, restore rehearsed in P9 |

---

## 12. Risk register

| Risk | Sev | Mitigation |
|---|---|---|
| **Solo bus factor** — illness or departure stops the project dead | Critical | Everything in git from day one. Decisions recorded as short ADRs. Infrastructure scripted, not hand-configured. Identify a backup developer at P0 even if unused |
| **Scope creep** — parity + improvements is already the widest option; additions land directly on the critical path | Critical | Phase exit criteria signed off in writing. New requests quoted against §13, never absorbed |
| **Content readiness** — client supplies catalogue, copy, bios and accreditation detail late. The most common cause of slipped launches | High | Content deadlines set at P0, tied to phase gates. Seeded placeholder content keeps the build moving regardless |
| **Payment gateway approval** — Stripe/PayPal onboarding for education and CEU sales can take weeks and may request documentation | High | Start both applications during **P0**, not P3 |
| **Accreditation rules** — credit awarding, attendance thresholds and certificate wording are prescribed by HRCI/SHRM and are not negotiable | High | Capture exact requirements in the P0 workshop; keep thresholds configurable per accrediting body, never hardcoded |
| **Live delivery integration** — Zoom/GoToWebinar registration APIs may be needed rather than plain links | Medium | Confirm at P0. If required, add 1.5 weeks to P5 explicitly rather than absorbing it |
| **Design iteration** — unbounded revision rounds consume front-end time invisibly | Medium | Two revision rounds per page included; further rounds quoted. Component library agreed before page building starts |

---

## 13. Descope ladder

Agree this order with the client **before** you need it. Cutting from the top costs the least
revenue and the least rework; cut in order and 32 weeks becomes 22 without touching anything
that earns money.

| # | Cut | Saves |
|---|---|---|
| 1 | **Forum.** Lowest commercial value, highest moderation burden, most credibly launched later | −1.5w |
| 2 | **Drip email automation.** Ship registration, reminder and receipt mails only | −1.2w |
| 3 | **Reporting dashboard.** Ship revenue and order totals only | −1.0w |
| 4 | **Quiz & lead engine.** A lead-gen extra, not a purchase path | −1.5w |
| 5 | **PayPal as second gateway.** Launch Stripe-only, add PayPal once live | −1.0w |
| 6 | **Blog.** Useful for SEO, but nothing depends on it | −1.0w |
| 7 | **Block library trimmed to six types**, rest added on demand | −1.0w |
| 8 | **Certificate auto-issue.** Admin issues manually at launch; automate in month two | −1.2w |

> **Never on the ladder:** payments, entitlements, the security baseline, backups, and the
> money-path test suite. If the timeline forces a choice, cut features — never the correctness
> of the things that take money and grant access.

---

## 14. Anti-patterns — banned by name

Every item below is a real defect from the predecessor, found by tracing control flow. They
are listed so no future session reintroduces one by copying "how the old system did it."

**Money and checkout**

1. **Never accept a price, discount or total from the request.** The predecessor took cart
   item prices from a request payload, the charge amount from a hidden `final_amount` field,
   and passed it straight to PayPal. Recompute everything from `offerings.price_cents`.
2. **Never let a request field select a fulfilment path.** `status=place_order` completed any
   cart for free. Fulfilment is driven by a verified `payments` row, nothing else.
3. **Never validate a coupon client-side**, and never serialise the coupon table into a page.
   The predecessor embedded `Coupon::all()` — every code, value and expiry — in the checkout
   HTML.
4. **Never write an order without a database transaction.** The predecessor wrote six tables
   in sequence with no transaction, after taking money.
5. **Never fulfil without idempotency.** No unique constraint on the gateway payment ID meant
   a replayed return URL could double-grant.
6. **Never rely on the browser returning.** With no webhook, a customer who closed the tab
   after paying was charged and received nothing, with no record written.
7. **Never store a unit price in a column called `total_price`.** Every revenue sum was wrong
   by a factor of quantity.
8. **Never store a gateway's string ID in a column that joins to a local integer key.**
   `transactions.order_id` held a PayPal ID; the table could never be joined to `orders`.

**Access and data**

9. **Never resolve access by re-deriving it in a controller.** Use the entitlement helper.
   The predecessor duplicated the logic four times with an inconsistent `is_package` filter,
   so package buyers could not see their own recordings.
10. **Never look up a user-owned record without scoping to the owner.** Three invoice methods
    used bare `findOrFail($id)`, exposing every customer's name, address and purchase history.
11. **Never build a URL proxy.** `file_get_contents($request->query('url'))` was an arbitrary
    file read and SSRF in eleven lines.
12. **Never compare loose strings for authorization or quota.** `'E_transcript'` vs
    `'E_transcripts'` silently disabled an entire quota check.

**Structure**

13. **Never leave a table without a migration.** ~25 tables and 43 columns existed only in
    production; the schema could not be rebuilt from source.
14. **Never create a model per page section.** Use the page builder.
15. **Never keep two versions of a theme, a dashboard or the app.** Delete what you replace.
16. **Never return an exception message as the HTTP response.** The predecessor's checkout
    did `return $e->getMessage();` with `APP_DEBUG=true` in production.

---

## 15. Glossary

| Term | Meaning |
|---|---|
| **Format** | A delivery mode of a webinar: live, recorded, transcript, download |
| **Offering** | A sellable SKU — a webinar or package in one format, at one price. The only source of price |
| **Entitlement** | A grant row saying a user may access a webinar in a format. The only source of access |
| **Package** | A bundle of webinars sold at a combined price |
| **Plan** | A membership tier granting an allowance of formats per term |
| **Allowance / quota** | How many of a format a plan grants per period; NULL means unlimited |
| **CEU** | Continuing Education Unit — the credits customers are actually buying |
| **Accreditation** | A body awarding credits: HRCI, SHRM, CPE, APA |
| **Attendee** | A named seat on an order line; may differ from the purchaser |

---

## 16. Reference

| Document | Where |
|---|---|
| Rebuild build plan (this brief's source) | https://claude.ai/code/artifact/04c29c89-1b96-4f0d-a87a-221ae7e9a18d |
| Predecessor commerce audit — 21 findings | https://claude.ai/code/artifact/28c79cc4-b989-440c-a04d-53763fa355b9 |
| Predecessor full knowledge base | `memory.md` in the old project root |

Both artifacts are private on claude.ai unless explicitly shared.

---

## 17. Week one checklist

1. Run the **commercial workshop**. Produce the offering matrix — every format sold, at what
   price, granting what access, for how long — and the accreditation rules per body.
   Everything downstream depends on this one document.
2. **Start the Stripe and PayPal merchant applications the same day.** Longest external lead
   time in the project.
3. Set **content deadlines** per phase gate and get them acknowledged in writing.
4. Create the repo. First commit: Laravel 12 skeleton, `.env` git-ignored, Pint, Larastan and
   Pest wired into GitHub Actions.
5. **Provision staging and prove the deploy pipeline end to end with an empty app.** Doing this
   now rather than at P9 is what makes a solo build reviewable weekly.
6. Write the schema migrations and factories against the offering matrix. This is the artefact
   worth spending real time on — every later phase either benefits from or fights it.
7. Agree the **descope ladder** with the client while the timeline is still comfortable and
   the conversation is unemotional.
