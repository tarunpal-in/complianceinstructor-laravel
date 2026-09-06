# Media manager — analysis and build plan

Requested by Tarun on 2026-09-06, scoped the same day: **images, PDFs and office
documents. No video.**

**New scope.** CLAUDE.md §6 has no media module, so this is quoted against §13
rather than absorbed — §8 has the cost, §10 has what to cut if the timeline will
not take it.

Read §3 and §6 before writing any migration. §3 decides how library files and
record-attached files coexist in one table; §6 is what "documents are
first-class" actually costs, and it is mostly server configuration rather than
code.

---

## 1. Why this exists

`spatie/laravel-medialibrary` is installed and working, but only as *contextual*
uploads bound to one record and one collection:

| Model | Collections |
|---|---|
| `Webinar` | `card`, `banner` |
| `Speaker` | `portrait` |
| `Industry` | `card` |

That is the right shape for those three. A webinar's banner is cropped 3:1 for
one specific hero and is never reused elsewhere; forcing it through a library
picker would add friction and buy nothing.

It stops being the right shape in two places.

**Documents have nowhere to live at all.** A CEU business runs on handouts,
slide decks, sample transcripts, accreditation statements and policy PDFs. Today
the only place a PDF can be uploaded is a webinar's Deliverables tab, which is
the private, entitlement-gated store for things customers paid for. There is no
home for a public one-pager, and no way to find a document again once uploaded.

**The page builder (P6) needs reuse.** The block library — hero, feature grid,
logo wall, testimonial slider, stat band, CTA — is where one asset appears in
many places: a partner logo on the homepage logo wall and again on About; the
same accreditation PDF linked from three landing pages. With contextual uploads
only, the client re-uploads every time, cannot find last month's file, and cannot
change a logo everywhere at once. That is how the predecessor reached **926
timestamp-prefixed files in `public/uploads`** with no index, which §3 of the
brief names as the thing medialibrary was chosen to replace.

Three smaller gaps close alongside:

- **Alt text has nowhere to live.** §7 P9 audits for WCAG AA; images without alt
  text fail it. There is no field for it anywhere today.
- **Settings has no logo or favicon upload**, which was asked for and is still
  outstanding.
- **Nothing knows where a file is used**, so deleting one is a guess.

---

## 2. Scope

**In scope:**

| Kind | Formats | Storage |
|---|---|---|
| Images | JPEG, PNG, WebP, AVIF, SVG | public disk, conversions queued |
| **PDF** | | public or private disk, page-1 thumbnail, text-searchable |
| **Office documents** | DOCX, XLSX, PPTX, CSV, TXT | public or private disk, mime icon |

**Out of scope, deliberately:**

- **Video, in all forms.** Dropped on request. Webinar recordings continue to
  live on `webinar_assets`, which already carries `url` for an externally hosted
  player and `disk_path` for the private disk, with `available_from` and
  `is_active` for release timing. This also keeps the brief's §1 non-goal — "no
  live video hosting, we link out" — intact for recordings too. Nothing about
  this plan blocks adding video later: it is one mime group, a poster-frame
  generator and a large-upload path, and §11 records what that would take.
- **Certificates and invoices.** Generated PDFs on a private disk, addressed by
  the record that owns them, never browsed as a library.
- **A public-facing gallery.** This is an admin tool.

---

## 3. The central decision — one table, two kinds of row

Spatie's `media` table is polymorphic: every row belongs to a model
(`model_type`, `model_id`) and a `collection_name`. A library item belongs to
nothing in particular, which is the whole problem.

The usual answer is `awcodes/filament-curator`, which ships its own media table
and its own picker. **Rejected**: it would mean two media systems side by side,
two upload paths, two sets of conversions, and two places to look when a file is
missing. That is precisely the duplication §3 ("a second toast library") and §4
rule 8 ("replaced code is deleted, not renamed `old_`") exist to prevent.

Instead: **library items are owned by a folder.** A `MediaFolder` model
implements `HasMedia`, and every library upload attaches to exactly one folder in
a `library` collection. One table, one API, conversions and responsive images
work unchanged, and the three existing contextual collections are not touched.

```
media.model_type = App\Models\MediaFolder   →  library item, reusable, browsable
media.model_type = App\Models\Webinar       →  attached to a record, read-only here
                   App\Models\Speaker
                   App\Models\Industry
```

The browser carries a scope switch — **Library · Attached to records · All** — so
the client can still answer "how much storage are the webinar banners using" and
"which speaker has no portrait", without those rows being editable or movable in
a place they do not belong.

### 3.1 Reuse: reference for blocks, copy for records

The two kinds of consumer want opposite behaviour, and conflating them is the
mistake to avoid.

**Page-builder blocks reference.** A `page_sections.data` JSON blob stores a
media **id**. Nothing is copied. Replace the file in the library and every block
using it updates at once — which is the entire point of a logo wall, and the
entire point of a policy PDF linked from four pages.

**Record collections copy.** Picking a library image for a webinar's `card` runs
`$media->copy($webinar, 'card')`, producing an independent row. This is correct,
not a compromise: the card is cropped 16:10 and the banner 3:1 for that one
record, so it is a derived artefact. If it referenced the library item, editing
the crop for one webinar would silently re-crop it everywhere.

---

## 4. Schema delta

```
media_folders     id, name, slug, parent_id NULL, sort_order, timestamps
                  INDEX (parent_id, sort_order)
                  -- one level of nesting in the UI; deep trees are where
                  -- clients lose files

media             + hash CHAR(64) NULL INDEX      -- sha256, duplicate detection
                  + page_count SMALLINT NULL      -- PDFs
                  + extracted_text MEDIUMTEXT NULL, FULLTEXT (extracted_text)
                  custom_properties.alt           -- no column: never filtered on
                  custom_properties.caption
                  custom_properties.uploaded_by

media_usages      id, media_id, usable_type, usable_id, context, created_at   NEW
                  UNIQUE (media_id, usable_type, usable_id, context)
                  INDEX (media_id)
                  -- 'context' is the field or block key, e.g. 'hero.background'

tags              id, name, slug, type NULL, timestamps                       NEW
taggables         tag_id, taggable_type, taggable_id                          NEW
                  UNIQUE (tag_id, taggable_type, taggable_id)
                  -- §5.7 already plans these for the blog. Building them here
                  -- means media and posts share one tag vocabulary instead of
                  -- the blog growing a second one at P7.

settings          + brand.logo_media_id, brand.logo_dark_media_id
                  + brand.favicon_media_id, brand.og_default_media_id
```

Three notes on the `media` table.

Adding columns means a migration against a vendor table. All three are additive
and nullable, so a medialibrary upgrade cannot collide with them, and §4 rule 1
requires they exist in a migration either way.

`alt` and `caption` go in `custom_properties` because they are displayed, never
filtered — except "missing alt text", which is a `JSON_EXTRACT ... IS NULL` scan
over a few thousand rows and does not justify a column.

`extracted_text` gets a **FULLTEXT** index, which is why it is a real column
rather than JSON. §6.2 explains what fills it. Note that FULLTEXT on MySQL 8 and
on the local MariaDB 10.4 behave differently enough to be worth a test — this is
already the pattern with `laravel/scout` on the MySQL driver (§3), so if the
index proves awkward the fallback is to index media through Scout alongside
webinars rather than query it directly.

`media_usages` is rebuilt, never patched: saving a page deletes that page's usage
rows and reinserts them. Drift is therefore impossible by construction, and
`php artisan media:reindex-usages` rebuilds the whole table if it is ever
doubted.

---

## 5. Feature inventory

Grouped by tier. §8 costs them; §10 says which can go.

### Tier 1 — the library

| Feature | Use |
|---|---|
| Grid view with thumbnails | How a non-technical client recognises a file |
| List view — size, type, dimensions, pages, date, uploader | How you audit; grid is useless for "what is eating 4 GB" |
| Search on name, file name, caption **and PDF contents** | See §6.2 — the difference between finding a handout and not |
| Filter: type · folder · tag · uploader · date range | |
| Filter: **missing alt text** | Turns the P9 WCAG AA audit from a crawl into a worklist |
| Folders — create, rename, move, bulk-move | The client's filing system |
| Tags | Cross-cutting where folders cannot overlap ("2026 campaign") |
| Multi-file drag-and-drop upload into the current folder | |
| Edit panel: name, alt text, caption, tags, folder | |
| **Replace file in place** | Same id, same URL, new bytes — a revised handout or a new logo updates everywhere at once. The main reason to have a library rather than scattered uploads |
| Image editor: crop, rotate, flip | Filament ships it |
| Download original · copy URL · open in new tab | |
| Bulk: tag, move, delete | |
| Permission-gated | `view_any_media` … `delete_media` added to `RolePermissionSeeder::CRUD_SUBJECTS`; Content Editor full, Finance and Support read |

### Tier 2 — documents, properly

| Feature | Use |
|---|---|
| **PDF page-1 thumbnail** | A wall of identical red PDF icons is not a library. §6.1 |
| **Inline PDF preview** in the detail panel | Confirm it is the right file without downloading it |
| **PDF page count** | "Is this the 2-page summary or the 40-page manual?" |
| **Full-text search inside PDFs** | The client uploads `final-v3.pdf` and never finds it again. Searching contents is what makes a document library usable. §6.2 |
| Mime icons for DOCX / XLSX / PPTX / CSV / TXT | §6.3 — the honest ceiling without a conversion service |
| Document-aware list columns — pages, extension badge | |

### Tier 3 — safety at scale

| Feature | Use |
|---|---|
| **Usage tracking** — "used in 3 places", each linked | |
| **Delete guard** — refuses to delete an in-use file, offers Replace | The classic media-manager disaster is deleting the homepage hero on a Friday. Highest-value item in this document |
| Duplicate detection on upload by sha256 | What stops a second `public/uploads` forming |
| Private items: private disk, lock badge, signed preview route **by media id** | §14 #11 bans URL proxies; the route never accepts a path |
| Orphan filter — zero usages, older than 90 days | A filter, never an automatic delete |
| Storage widget — totals by disk and type | |
| Activity log on upload, replace and delete, with the actor | §9 — the predecessor had no audit trail |

### Tier 4 — connecting it up

| Feature | Use |
|---|---|
| `MediaPicker` Filament field — modal browser, single or multiple, returns ids | What the P6 page builder consumes |
| "Choose from library" beside the three contextual uploads | Copies, per §3.1 |
| Brand settings: logo, dark logo, favicon, default OG image | The outstanding request from the settings work |

---

## 6. Documents

This is the part the scope change makes load-bearing, and most of it is server
configuration rather than code.

### 6.1 PDF thumbnails

Medialibrary already ships a `Pdf` image generator. It needs two things we do not
have:

```bash
composer require spatie/pdf-to-image        # ^3.0
# server: php-imagick + ghostscript
```

With both present, page-1 thumbnails fall out of the existing conversion
pipeline with **zero custom code** — the generator's `requirementsAreInstalled()`
check simply starts returning true. Without them it silently skips generation and
you get a mime icon, so the failure mode is cosmetic rather than an error.

Two deployment traps, both worth writing into `docs/deployment.md`:

- **ImageMagick's `policy.xml` blocks PDF by default on most distributions**
  (`<policy domain="coder" rights="none" pattern="PDF" />`). Ubuntu 24.04 ships
  this. Thumbnails will fail silently until it is changed, and the symptom looks
  identical to "Ghostscript is missing".
- Local PHP here reports `imagick=0`, so this cannot be tested locally until
  Imagick is installed in XAMPP. Plan to verify it on staging.

### 6.2 Full-text search inside PDFs

The feature that decides whether a document library is used or abandoned. A
compliance business accumulates hundreds of near-identically named PDFs; matching
on file name alone means the client cannot find anything they did not upload that
week.

```bash
composer require smalot/pdfparser
```

Pure PHP, no extension needed. On upload, a queued job extracts the text and the
page count into `media.extracted_text` and `media.page_count`, capped at ~1 MB of
text per file so a 400-page manual cannot bloat the row. Search then matches
name, file name, caption and contents together.

Scanned PDFs contain no text layer and will extract nothing — that is expected,
not a bug, and OCR is firmly out of scope.

DOCX text extraction is cheap for the same reason (it is a zip of XML) and can be
added later behind the same job if it proves useful; it is not in this plan.

### 6.3 Office documents

DOCX, XLSX, PPTX, CSV, TXT get a mime icon, size, download and full library
metadata — folders, tags, alt/caption, usage tracking, replace-in-place. No
preview is possible without a document conversion service (LibreOffice headless
or a paid API), and neither is justified here. That is the honest ceiling: these
are attachments, not visual assets.

### 6.4 Upload limits

PHP here is `upload_max_filesize=40M`, `post_max_size=50M`, `memory_limit=128M`.
A 60 MB slide deck fails today, and the symptom is a bare nginx 413 rather than a
useful message. Raising both to 128 M, plus nginx `client_max_body_size 128m`,
covers documents comfortably. Without video there is no case for going higher.

---

## 7. Server requirements

Additions to `docs/deployment.md` when this lands:

| Need | For | If missing |
|---|---|---|
| `php-imagick` + `ghostscript` | PDF page-1 thumbnails | Generic mime icon, no error |
| ImageMagick `policy.xml` PDF coder enabled | Same | Same — silent, and easily misdiagnosed |
| `upload_max_filesize` / `post_max_size` = 128M | Slide decks, large PDFs | Bare nginx 413 |
| nginx `client_max_body_size 128m` | Same | Same |
| Horizon running | Conversions and text extraction are queued (§4 rule 7) | Thumbnails and search never appear |

---

## 8. Build plan

### Tier 1 — the library · 5.5 days

1. Publish `config/media-library.php`; custom `App\Models\Media` extending
   spatie's, wired via `media_model` — needed for `usages()`, the hash, page
   count and the alt/caption accessors — 0.5
2. Migrations: `media_folders`, `media_usages`, `tags`, `taggables`, and the
   `media` column additions. Factories and seeder rows (§4 rule 1, §5.9) — 0.75
3. `MediaFolder` model, `library` collection, queued `thumb` and `preview`
   conversions, SVG sanitisation — 0.5
4. `MediaResource`: grid and list views, search, filters, sorting — 2.0
5. Upload, edit panel, replace-in-place, download, copy URL, image editor — 1.0
6. Folder tree sidebar; create, rename, move, delete; bulk move — 0.5
7. Permissions, nav placement (Content · sort 30), one policy test per role — 0.25

### Tier 2 — documents · 1.75 days

8. `spatie/pdf-to-image`, conversion config, deployment notes and the
   `policy.xml` trap — 0.5
9. Queued extraction job: text + page count, size cap, re-run on replace — 0.75
10. Search across name, file name, caption and contents; inline PDF preview;
    mime icons and document list columns — 0.5

### Tier 3 — safety at scale · 4.0 days

11. `media_usages` write-on-save, `media:reindex-usages`, the "used in" panel and
    the delete guard — 1.5
12. sha256 on upload, duplicate prompt — 0.5
13. Private disk, lock badge, signed preview route by id — 1.0
14. Orphan filter, storage widget, activity-log subscriber — 0.5
15. Tests: delete guard, usage reindex, signed-route scoping — 0.5

### Tier 4 — connecting it up · 2.5 days

16. `MediaPicker` field and the page-builder block integration — 1.5
17. "Choose from library" copy action on the three existing collections — 0.5
18. Brand settings: logo, dark logo, favicon, default OG image — 0.5

**Total ≈ 13.75 days ≈ 2.75 weeks.**

Dropping video removed roughly 2 days (large-file upload path, poster frames,
player handling); documents added roughly 1.75 back. The net is close to flat,
but the 1.75 is spent on something the client will use daily rather than on an
upload path that would have been replaced by a hosting platform anyway.

### Where it goes in the phase plan

Tier 1, Tier 2 and Tier 4 are **prerequisites for P6** — building the page
builder without a picker means building it twice, and the marketing pages need
policy PDFs on day one. The natural slot is the front of P6 (W20), which is also
when the client starts loading real content and will feel the absence
immediately.

Tier 3 can follow inside P6 without blocking anything.

---

## 9. Guardrails carried from CLAUDE.md

- **Never build a URL proxy** (§14 #11). The signed preview route takes a media
  **id** and resolves the path server-side. It never accepts a path, a disk name
  or a URL. The predecessor's `file_get_contents($request->query('url'))` was an
  arbitrary file read and SSRF in eleven lines.
- **Private files stay on the private disk** (§9). The library's public disk is
  for marketing assets only. Nothing a customer paid for is browsable here — and
  with video out of scope, webinar deliverables never enter this system at all.
- **Policies on every model** (§4 rule 4). `Media` and `MediaFolder` both get
  one, both asserted by test — §9 requires policy coverage by test for every
  model exposed in the admin.
- **Conversions and extraction are queued** (§4 rule 7). A 12 MB PNG or a
  200-page PDF must not block the request that uploaded it.
- **Delete is guarded, not clever.** Refusing with "used in 3 places, here they
  are" beats a cascade that leaves three pages with broken images.
- **Eager-load the grid.** `Model::shouldBeStrict()` is on outside production, so
  a missing `with()` on the folder and usage count throws rather than quietly
  costing 60 queries on a 60-thumbnail page.
- **Alt text is a first-class field, not a nicety.** §7 P9 audits WCAG AA, and
  retrofitting alt text across a loaded catalogue is far worse than capturing it
  at upload.
- **SVG is never converted or edited**, and is sanitised on upload. An SVG is a
  document that can carry script, and it would be served from the same origin as
  the admin panel.

---

## 10. If the timeline will not take 2.75 weeks

Cut in this order. The first three bring it to **2 weeks** and still deliver
everything P6 needs plus working document handling.

| # | Cut | Saves |
|---|---|---|
| 1 | **Orphan filter and storage widget.** Useful in year two, not at launch | −0.5d |
| 2 | **Tags.** Folders alone suffice at a few hundred files; `tags`/`taggables` still get built at P7 for the blog | −0.5d |
| 3 | **Duplicate detection.** Costs disk, not correctness | −0.5d |
| 4 | **List view.** Grid only — but you lose the storage audit | −0.5d |
| 5 | **PDF full-text search.** Filename search only | −0.75d |

**Never cut:** the delete guard and usage tracking, alt text, the private-disk
split, PDF thumbnails, or the policy tests. The first stops the client breaking
the homepage; PDF thumbnails are most of why documents are usable at all; the
rest are §9 and §7 P9 obligations that get more expensive the longer they wait.

---

## 11. What was built, and where it differs from this plan

Built 2026-09-06/07. Everything in Tiers 1–3 is in, plus the brand settings from
Tier 4. Five decisions changed during the build; each is here because the plan
would otherwise read as wrong rather than superseded.

### 11.1 PDF thumbnails do not use Imagick

§6.1 called for `spatie/pdf-to-image` plus `php-imagick`. Instead
`App\Media\PdfPageImageGenerator` shells out to **poppler's `pdftoppm`**, falling
back to **Ghostscript**, and replaces spatie's `Pdf` generator in
`config/media-library.php`.

Imagick delegates PDF rendering to Ghostscript anyway, so the extension adds a
layer that renders nothing — and it drags in the `policy.xml` trap §6.1 warned
about. Dropping it means one apt package (`poppler-utils`), no PHP extension, and
no silent failure mode that looks like a missing Ghostscript. Neither binary is
present on this machine, so the generator reports itself unavailable and files
fall back to a mime icon, exactly as intended.

### 11.2 Extracted text lives in a sidecar table

§4 put `extracted_text` on `media`. It is now `media_documents` (media_id
primary key, page_count, extracted_text, extracted_at, extract_error).

A grid selects every column of sixty rows to draw sixty thumbnails. A MEDIUMTEXT
on `media` would make that query haul sixty document bodies it never renders.

### 11.3 No FULLTEXT index

§4 specified one. Dropped, for two reasons: tests run on SQLite, which has no
such index; and MySQL's default `innodb_ft_min_token_size` of 3 plus its stopword
list would silently drop exactly the terms this catalogue is searched by —
*FMLA*, *I-9*, *OSHA*. A LIKE scan over a few thousand rows is well inside what an
admin search can afford, and `laravel/scout` is already in the stack if it is
outgrown.

### 11.4 Brand settings came forward from Tier 4

`Setting` is the first model to implement `ReferencesMedia`, so the logo, dark
logo, favicon and default social image are picked from the library and write
`media_usages`. This was Tier 4 item 15; doing it now means the delete guard has
something real to guard from day one rather than waiting for P6.

### 11.5 The picker is a select, not a modal browser

`App\Filament\Forms\Components\MediaPicker` is a searchable select over library
files, not the modal browser Tier 4 item 13 describes. It is enough for four
brand fields. The page builder at P6 will want the full modal, and this class is
where it goes.

### Not built

| Item | Why |
|---|---|
| Private disk + signed route | Nothing can be marked private yet, so the route would guard an empty set. `config/media.php` holds `private_disk` and `signed_url_minutes` ready for it. The two things it protects — webinar recordings and certificates — are out of this system by §2 |
| Storage-usage widget | Cosmetic; the list view sorts by size, which answers the same question |
| "Choose from library" on webinar/speaker/industry | Tier 4 item 14, wanted at P6 alongside the modal picker |
| Image editor (crop/rotate) | Filament ships it; one call on the replace flow when someone asks |
| Document version history | Still an open decision — see below |

### Server requirements, restated

`docs/deployment.md` needs: **`poppler-utils`** (PDF thumbnails; Ghostscript is
the fallback), `upload_max_filesize` and `post_max_size` at 128M, nginx
`client_max_body_size 128m`, and Horizon running so conversions and extraction
actually happen. No Imagick, no ffmpeg.

---

## 12. Open decisions

1. **Document version history.** Replace-in-place overwrites. For a compliance
   business there is an argument for keeping the previous file and being able to
   answer "which version of the handout did the March cohort receive" — a
   `media_versions` table plus a rollback action, about **1 day**. Not in the plan
   above; worth a yes or no before Tier 1 item 5 is built, because retrofitting
   it means changing how replace works.
2. **Should the client be able to delete media at all?** Content Editor holds
   full CRUD in the current seeder. With the delete guard in place that is
   defensible, but restricting delete to Super Admin is the safer default and
   costs nothing to change later.
3. **Does the client want an image CDN?** §11 already specifies Cloudflare. If
   edge resizing is enabled, the `preview` conversion can be dropped and
   originals served with resize parameters — smaller build, smaller disk.
4. **Video, later.** Out of scope by decision, not by accident. Adding it would
   mean: a mime group and player in the grid, poster frames via `ffmpeg` plus
   `php-ffmpeg/php-ffmpeg`, and a large-file upload path — presigned S3 multipart
   direct from the browser, or a hosting platform with signed playback. Roughly
   3 days, or near zero if recordings simply keep linking out through
   `webinar_assets.url` as they do now.
