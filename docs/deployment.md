# Deployment — CloudPanel VPS

> **Audience:** the developer deploying Compliance Instructor to a Linux VPS running
> CloudPanel. Two workflows: **Part A** is a first-time (fresh) deploy; **Part B**
> is pulling updates onto a VPS that already runs the app.

## Stack this app needs on the server

| Component | Version / note |
|---|---|
| OS | Ubuntu 24.04 LTS (CloudPanel's supported base) |
| Web / PHP | Nginx + **PHP 8.3** FPM (CloudPanel manages both) |
| PHP extensions | bcmath, ctype, curl, dom, fileinfo, gd (or imagick), intl, mbstring, pdo_mysql, openssl, zip, exif, **redis** |
| Database | MySQL 8 (CloudPanel default) |
| Cache / session / queue | **Redis** (installed via apt, not CloudPanel) |
| Queue worker | **Horizon** under **Supervisor** |
| Asset build | **Node 22 LTS** (Vite 8 + Tailwind 4) + Composer 2 |
| PDFs (certificates/invoices) | Headless **Chromium** for `spatie/laravel-pdf` (Browsershot); `dompdf` is the fallback |
| Object storage | Cloudflare **R2** (S3-compatible) for media + generated PDFs |

Placeholders used below — substitute your own:
`<domain>` (e.g. `app.complianceinstructor.com`) · `<site-user>` (the CloudPanel site's Linux user) · `<app-dir>` = `/home/<site-user>/htdocs/<domain>` · `<db-name>` / `<db-user>` / `<db-pass>`.

---

# Part A — Fresh deployment

### 1. Create the site in CloudPanel (web UI)

1. Log in to CloudPanel (`https://<server-ip>:8443`).
2. **Sites → Add Site → Create a PHP Site.** Domain `<domain>`, **PHP version 8.3**, application `Generic` (we set the Laravel vhost in step 6). CloudPanel creates the Linux user `<site-user>` and the directory `<app-dir>`.
3. **Databases → Add Database.** Note the generated `<db-name>`, `<db-user>`, `<db-pass>` (host `127.0.0.1`, port `3306`).
4. **Site → SSL/TLS → Let's Encrypt → Issue** (do this after DNS points at the server; can be done at the end).

### 2. Install server-level dependencies (SSH as root/sudo, once per server)

```bash
# Redis
apt update && apt install -y redis-server
systemctl enable --now redis-server

# PHP 8.3 redis extension (if CloudPanel didn't include it) + verify others
apt install -y php8.3-redis
# gd/intl/zip/mbstring/curl/xml/bcmath usually ship with CloudPanel's php8.3; verify:
php8.3 -m | grep -E 'redis|gd|intl|zip|mbstring|bcmath|exif|pdo_mysql'

# Composer (system-wide)
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Supervisor (runs Horizon)
apt install -y supervisor && systemctl enable --now supervisor

# Chromium for PDF generation (Browsershot). Skip if you rely on the dompdf fallback.
apt install -y chromium-browser fonts-liberation
```

### 3. Install Node 22 for the site user (SSH as `<site-user>`)

CloudPanel does not manage Node, so install it per-user with nvm:

```bash
# as <site-user> (CloudPanel → Site → open the SSH/terminal, or: su - <site-user>)
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
source ~/.bashrc
nvm install 22
nvm alias default 22
node -v   # v22.x
```

### 4. Give the server read access to the private repo

The repo is private, so generate a **deploy key** on the server and add it to GitHub (read-only):

```bash
# as <site-user>
ssh-keygen -t ed25519 -C "cloudpanel-<domain>" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Copy the printed key into **GitHub → repo → Settings → Deploy keys → Add deploy key** (leave *Allow write access* unchecked). Then:

```bash
ssh -o StrictHostKeyChecking=accept-new -T git@github.com   # should greet you by username
```

### 5. Clone the app

CloudPanel created `<app-dir>` with an empty web root. Clone into it:

```bash
# as <site-user>
cd /home/<site-user>/htdocs
rm -rf <domain>            # remove the empty placeholder dir CloudPanel made
git clone git@github.com:tarunpal-in/complianceinstructor-laravel.git <domain>
cd <domain>
git checkout main
```

### 6. Point the vhost at `public/` (CloudPanel UI → Site → Vhost)

Laravel is served from the `public/` subfolder. In **Site → Vhost**, make sure the `root` and the Laravel rewrite are set:

```nginx
server {
  ...
  root /home/<site-user>/htdocs/<domain>/public;     # <-- the public subfolder
  index index.php index.html;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }
  # CloudPanel's generated PHP-FPM location block (handler, fastcgi_pass) stays as-is.
  ...
}
```

Save — CloudPanel reloads Nginx.

### 7. Environment file

```bash
# as <site-user>, in <app-dir>
cp .env.example .env
chmod 600 .env
php8.3 artisan key:generate
nano .env     # fill in the values — see "Appendix A: .env essentials"
```

### 8. Install dependencies and build assets

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build          # compiles public/build (gitignored, so built on the server)
```

### 9. Database, storage, and caches

```bash
php8.3 artisan migrate --force
php8.3 artisan db:seed --force            # FIRST deploy only — seeds roles, settings, demo data
php8.3 artisan storage:link

# Production caches (APP_ENV=production also turns OFF strict lazy-load guards)
php8.3 artisan optimize                   # config + routes + views + events
php8.3 artisan icons:cache
php8.3 artisan filament:optimize
```

> If this is a client-facing launch (not a demo), **do not run the demo catalogue seeders**. Seed only what you need (e.g. `php artisan db:seed --class=RolePermissionSeeder`, `SettingSeeder`, `LegalPagesSeeder`). `db:seed --force` runs the full `DatabaseSeeder`, which includes demo webinars/orders.

### 10. Permissions

PHP-FPM runs as `<site-user>`, and you cloned as `<site-user>`, so ownership is already correct. Just make sure the writable dirs are:

```bash
chmod -R ug+rwX storage bootstrap/cache
```

### 11. Queue worker (Horizon under Supervisor)

Create `/etc/supervisor/conf.d/horizon-<domain>.conf` (as root):

```ini
[program:horizon-<domain>]
process_name=%(program_name)s
command=php8.3 /home/<site-user>/htdocs/<domain>/artisan horizon
autostart=true
autorestart=true
user=<site-user>
redirect_stderr=true
stdout_logfile=/home/<site-user>/htdocs/<domain>/storage/logs/horizon.log
stopwaitsecs=3600
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start horizon-<domain>:*
```

### 12. Scheduler (CloudPanel UI → Site → Cron Jobs)

Add one cron job (CloudPanel runs it as `<site-user>`):

```
* * * * * php8.3 /home/<site-user>/htdocs/<domain>/artisan schedule:run >> /dev/null 2>&1
```

### 13. Go live

- Confirm DNS → server IP, then issue the **Let's Encrypt** cert (step 1.4) if not done.
- Point external webhooks at the live URLs: **PayPal** webhook → `https://<domain>/webhooks/paypal` (and Stripe if enabled). Set the matching `PAYPAL_WEBHOOK_ID` / `STRIPE_WEBHOOK_SECRET` in `.env`, then `php8.3 artisan config:cache`.
- Smoke-test: home page, a webinar, add-to-cart → checkout, `/admin` login, and a queued job (e.g. an invoice PDF) processing in Horizon (`https://<domain>/horizon`).

---

# Part B — Pull updates onto an existing deployment

Everything below assumes the server was set up via Part A. Put this script at `<app-dir>/deploy.sh`, make it executable (`chmod +x deploy.sh`), and run it as `<site-user>` for each release.

```bash
#!/usr/bin/env bash
# Deploy the latest main onto this CloudPanel VPS. Run as the site user.
set -euo pipefail

cd /home/<site-user>/htdocs/<domain>

echo "→ Maintenance mode on"
php8.3 artisan down --retry=15 || true

echo "→ Pull latest code"
git fetch origin
git reset --hard origin/main        # exact match to the pushed main (no local drift)

echo "→ PHP deps"
composer install --no-dev --optimize-autoloader --no-interaction

echo "→ Front-end build"
npm ci
npm run build

echo "→ Migrations (safe, no seeding)"
php8.3 artisan migrate --force

echo "→ Rebuild caches"
php8.3 artisan optimize:clear
php8.3 artisan optimize
php8.3 artisan icons:cache
php8.3 artisan filament:optimize

echo "→ Restart workers (Horizon picks up new code)"
php8.3 artisan horizon:terminate
php8.3 artisan queue:restart

echo "→ Maintenance mode off"
php8.3 artisan up

echo "✓ Deployed $(git rev-parse --short HEAD)"
```

Run it:

```bash
cd /home/<site-user>/htdocs/<domain>
./deploy.sh
```

### Notes for updates

- **Never run `db:seed` on an update** — it would re-seed/overwrite content. Run a *specific* idempotent seeder only when a release adds one, e.g. the legal pages:
  ```bash
  php8.3 artisan db:seed --class=LegalPagesSeeder --force
  ```
- **`git reset --hard origin/main`** is used instead of `git pull` so the server always matches the pushed `main` exactly. It discards any local edits on the server — which is what you want (never edit code on the server). `.env`, `storage/`, and the gitignored `public/build`/`uploads` are untouched.
- If a release changes `.env.example` with a new required variable, add it to the server's `.env` **before** running the script, then let the script's `optimize` re-cache config.
- Horizon **must** be restarted every deploy (`horizon:terminate` → Supervisor relaunches it) or queued jobs keep running the old code.

---

# Part C — Moving database content from local to the server (safely)

## The rule first: schema vs content

Two different things get confused here — keep them apart:

- **Schema (tables, columns, indexes)** is **never** moved by importing SQL. It travels as **migrations**, which the deploy already runs (`php artisan migrate --force`). If you changed the structure locally, you did it by adding a migration, and that migration applies itself on the server. Importing a schema dump on top of migrations desyncs the two and breaks the next `migrate`.
- **Content (rows)** — the catalogue, CMS pages, settings — is what you might legitimately want to move. Prefer **seeders** (versioned, re-runnable, safe) over raw SQL.

> ⚠️ **Never import your whole local database over a live production database.** It overwrites real orders, users, payments and attendance with local test data — unrecoverable without a backup. A full-DB import is only safe **before go-live**, when the server has no real data yet.

## Preferred: move content as a seeder

For content you authored locally and want on the server, write a **seeder**, commit it, deploy, and run just that seeder:

```bash
# on the server, after deploying the code that contains the seeder
php8.3 artisan db:seed --class=YourContentSeeder --force
```

Use `updateOrCreate`/`firstOrCreate` in it (like `LegalPagesSeeder`) so re-running never duplicates. This is the only method that is version-controlled, reviewable and safe to repeat — it is how this project is designed to move content (CLAUDE.md §4 / §5.9).

## When you must move actual tables (initial load or reference data)

Legitimate cases: the **first** data load before go-live, or copying a few **reference** tables (e.g. a catalogue you built in the admin locally). **Never** `orders`, `users`, `payments`, `subscriptions`, `entitlements` once the site is live.

### Step 1 — Back up the server database first (always)

```bash
# on the server
mysqldump -u root -p <db-name> | gzip > ~/backup-<db-name>-$(date +%F-%H%M).sql.gz
```

Or **CloudPanel → Databases → ⋯ → Export**. Do not skip this.

### Step 2 — Export the chosen tables from your local machine

Export **only** the tables you intend to move, as UTF-8MB4:

```bash
# locally — name the specific tables (not the whole DB, unless it's a first full load)
mysqldump -u root -p --default-character-set=utf8mb4 \
  --single-transaction --no-tablespaces \
  ceu_platform webinars offerings speakers industries accreditations webinar_accreditation \
  > catalogue.sql
```

- For a **first-time full load** onto an empty server DB: `mysqldump ... ceu_platform > full.sql`.
- Add `--no-create-info` (data only) when the server tables already exist via migrations and you only want their rows — and truncate those tables on the server first if you want a clean replace rather than an append.

### Step 3a — Import via phpMyAdmin (CloudPanel ships it)

**CloudPanel → Databases → your DB → phpMyAdmin.**

1. Select the **target database** in the left sidebar — the existing CloudPanel `<db-name>`, never a new one from the dump.
2. **Import** tab → choose the `.sql` (or `.sql.gz`) → **Go**.
3. Watch for collation / foreign-key errors. If the file is larger than phpMyAdmin's upload limit (often 2–256 MB) it is rejected — gzip it, or use the CLI method below.

> The dump must **not** contain `CREATE DATABASE` / `USE <other-db>`, and must not `DROP` tables that hold server data.

### Step 3b — Import via CLI (better for anything large)

No upload limit, faster. Copy the dump up and import into the existing DB:

```bash
# from your local machine
scp catalogue.sql <site-user>@<server-ip>:~

# on the server
mysql -u <db-user> -p <db-name> < ~/catalogue.sql
```

If foreign-key order causes errors on a full load:

```bash
( echo "SET FOREIGN_KEY_CHECKS=0;"; cat ~/catalogue.sql; echo "SET FOREIGN_KEY_CHECKS=1;" ) \
  | mysql -u <db-user> -p <db-name>
```

### Step 4 — After any import

```bash
php8.3 artisan migrate --force        # apply any migrations the imported data predates
php8.3 artisan optimize:clear && php8.3 artisan optimize
php8.3 artisan horizon:terminate
```

## Quick decision guide

| You changed… | Move it by… |
|---|---|
| Table structure | nothing extra — `migrate --force` runs on deploy |
| Settings / CMS pages / roles | a **seeder** (`db:seed --class=…`) |
| A catalogue built in local admin, **pre-launch** | export those tables → import (above) |
| First-ever load on an **empty** server DB | full export → import, then `migrate --force` |
| Orders / users / payments on a **live** site | **don't** — that data lives on the server; never overwrite it |

## Charset note (MariaDB local → MySQL 8 server)

Local is MariaDB (XAMPP), the server is MySQL 8. Dumps import fine, but if MySQL 8 rejects a collation (`utf8mb4_general_ci` vs `utf8mb4_0900_ai_ci`), add `--compatible=mysql` to the local `mysqldump`, or find-and-replace the collation in the `.sql` before importing. Always keep **`utf8mb4`**, never `utf8`.

---

# Part D — Moving images and media to the server

## How media is stored here (so you move the right thing)

- Files live on a **disk**; the `media` table stores only a **reference** (disk + path). Media is **never in git** (`storage/`, `uploads/`, `public/build` are gitignored), so it moves separately from code — and it must move **together with its `media` rows** (Part C), onto the disk those rows point at.
- Which disk is chosen by env:
  - **`MEDIA_DISK`** — the media library. Default `public` = `storage/app/public`, served via the `/storage` symlink. **In production set `MEDIA_DISK=r2`.**
  - **`WEBINAR_ASSETS_DISK`** / **`FILESYSTEM_DISK`** — private files (recordings, downloads) and the default disk. Set to `r2` in production.
- **Generated PDFs** (certificates, invoices) are **not** moved — Browsershot regenerates them on the server on demand.
- **Private media** (recordings, handouts) is served through **signed, expiring URLs** (§9) — keep it on the private disk/bucket, never the public one.

## Recommended: one R2 bucket, nothing to move

The least-effort, most-robust pattern (the `.env` already has R2):

- Set `MEDIA_DISK=r2`, `WEBINAR_ASSETS_DISK=r2`, `FILESYSTEM_DISK=r2` on the server — and optionally locally too (use a `dev/` path prefix or a separate dev bucket so dev doesn't pollute production).
- Then media uploaded through the admin, on either machine, lands in R2 and is immediately readable by the server. There is **nothing to copy** — the files are already where the server looks. Just make sure the `media` **rows** are present too (move them as a seeder or a table export, Part C). This is what I'd use for the demo and launch.

## Keeping media on the VPS only (no object storage)

If you don't want R2/S3 and prefer everything on the server's own disk:

**1. Point every disk at the local filesystem** (server `.env`; leave the R2/AWS keys blank):

```ini
FILESYSTEM_DISK=local
MEDIA_DISK=public            # media library → storage/app/public, served at /storage
WEBINAR_ASSETS_DISK=local    # private recordings/handouts → storage/app/private, signed URLs
```

Then `php8.3 artisan config:cache`. Private media still serves through signed, expiring URLs — the local/private disk does this through Laravel, no object storage needed.

**2. Upload the files**, preserving the directory structure — pick one:

```bash
# rsync (best: incremental, resumable)
rsync -avz storage/app/public/  <site-user>@<server-ip>:/home/<site-user>/htdocs/<domain>/storage/app/public/
rsync -avz storage/app/private/ <site-user>@<server-ip>:/home/<site-user>/htdocs/<domain>/storage/app/private/

# or tar + scp (good for many small files)
tar czf media.tgz -C storage/app public private
scp media.tgz <site-user>@<server-ip>:~
ssh <site-user>@<server-ip> 'tar xzf ~/media.tgz -C /home/<site-user>/htdocs/<domain>/storage/app'
```

Or a **SFTP GUI** (FileZilla with the CloudPanel site user's SSH credentials), or CloudPanel's web **File Manager** (upload a zip → extract) — dropping `storage/app/public` and `storage/app/private` into the same paths.

**3. Link and fix permissions** (on the server):

```bash
cd /home/<site-user>/htdocs/<domain>
php8.3 artisan storage:link      # public/storage -> storage/app/public (nginx serves /storage)
chmod -R ug+rwX storage
```

Upload **as the site user** so ownership is correct; if you used root, `chown -R <site-user>:<site-user> storage`.

**Two trade-offs with VPS-only storage:**
- **Backups matter more** — no object-storage redundancy, so make sure `storage/app` is in your backup (`spatie/laravel-backup`) and stored **off the server**.
- **Disk space** — the WordPress media library is ~900+ files; it all sits on the VPS disk now, so watch free space as the catalogue grows.

## Option B — Re-import from the WordPress source on the server

Since the catalogue media came from the WordPress backup, the cleanest way to populate a fresh server is to **re-run the import there**, so the server writes both the `media` rows and the files itself — perfectly in sync, onto whatever `MEDIA_DISK` is set:

```bash
# 1. send the WordPress uploads backup up (it's gitignored, so transfer it by hand)
rsync -avz uploads/uploads/ <site-user>@<server-ip>:/home/<site-user>/htdocs/<domain>/uploads/uploads/

# 2. make the legacy WordPress DB available to the import's legacy connection, then on the server:
php8.3 artisan import:media --force
```

It is idempotent and dedupes by content hash, so it is safe to re-run. This avoids transferring the Laravel media library at all.

## Option C — Copy the files directly (curated media / filesystem disk)

When you curated media in the local admin and aren't re-importing, copy the files to the matching disk, preserving paths.

**If production media is on R2 (`MEDIA_DISK=r2`)** — sync the files into the bucket with **rclone** at the same relative paths spatie uses (`{media_id}/file.ext` under the disk root):

```bash
rclone config                               # once: type s3, provider Cloudflare R2, your key/secret/endpoint
rclone copy storage/app/public  r2:<R2_BUCKET> --progress   # public media library
rclone copy storage/app/private r2:<R2_BUCKET> --progress   # private media (match the prefix your disk uses)
```

**If production media is on the server filesystem (`MEDIA_DISK=public`)** — rsync the storage dirs up, then relink:

```bash
# from local — preserve the exact structure
rsync -avz storage/app/public/  <site-user>@<server-ip>:/home/<site-user>/htdocs/<domain>/storage/app/public/
rsync -avz storage/app/private/ <site-user>@<server-ip>:/home/<site-user>/htdocs/<domain>/storage/app/private/

# on the server
php8.3 artisan storage:link
chmod -R ug+rwX storage
```

After moving **originals** without their generated thumbnails/conversions, rebuild them on the server:

```bash
php8.3 artisan media-library:regenerate
```

## The golden rule

**Files and `media` rows are a pair.** After moving media, open a few records in admin (a webinar card, a speaker photo, an accreditation logo) and confirm the images resolve. A broken image means the file and its DB row disagree on disk or path — re-check that both landed on the same disk with the same relative path.

---

# Appendix A — `.env` essentials (production)

Fill these in `<app-dir>/.env`. The full list is in `.env.example`; these are the ones that matter in production:

```ini
APP_NAME="Compliance Instructor"
APP_ENV=production            # turns off strict lazy-load guards + debug
APP_DEBUG=false               # NEVER true in production (§9)
APP_URL=https://<domain>

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<db-name>
DB_USERNAME=<db-user>
DB_PASSWORD=<db-pass>

# Redis for cache / session / queue
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null           # set one if you configured Redis auth

SESSION_SECURE_COOKIE=true    # HTTPS only
SESSION_DOMAIN=<domain>

# Object storage (Cloudflare R2) for media + generated PDFs
FILESYSTEM_DISK=r2
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
R2_BUCKET=...
R2_ENDPOINT=https://<account>.r2.cloudflarestorage.com
R2_URL=https://<your-public-r2-domain>
R2_USE_PATH_STYLE_ENDPOINT=true

# Transactional mail (Postmark/SES — not shared SMTP)
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=...
MAIL_FROM_NAME="${APP_NAME}"

# Payments (PayPal is the default gateway; Stripe held disabled)
PAYPAL_MODE=live
PAYPAL_CLIENT_ID=...
PAYPAL_CLIENT_SECRET=...
PAYPAL_WEBHOOK_ID=...
# STRIPE_* only if/when Stripe is enabled in admin

# Error tracking
SENTRY_LARAVEL_DSN=...
```

> reCAPTCHA keys and the marketing pixel IDs are **not** env vars — the client sets them in **admin → Settings → Integrations**.

---

# Appendix B — Troubleshooting & rollback

**Rollback a bad release** (git deploys aren't atomic):
```bash
cd /home/<site-user>/htdocs/<domain>
git reset --hard <previous-good-commit>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php8.3 artisan migrate --force      # only if the bad release's migration is safe to keep; otherwise restore DB from backup
php8.3 artisan optimize:clear && php8.3 artisan optimize
php8.3 artisan horizon:terminate
```

**Common issues**
- *500 after deploy* → check `storage/logs/laravel.log`; usually a missing `.env` var or stale cache. Run `php8.3 artisan optimize:clear` then re-`optimize`.
- *Blank/old assets* → `npm run build` didn't run or browser cache; confirm `public/build/manifest.json` updated.
- *Admin file uploads fail ("… failed to upload")* → see the dedicated section below.
- *Queued jobs not running* → `supervisorctl status`; `php8.3 artisan horizon:status`.
- *PDFs blank/failing* → Chromium not installed or not found by Browsershot; either install `chromium-browser` (step 2) or confirm the dompdf fallback is active.
- *Permissions* → `chmod -R ug+rwX storage bootstrap/cache` as `<site-user>`.

### Admin file uploads fail ("… failed to upload")

Livewire/Filament uploads go through a **signed URL**, so on a proxied HTTPS host (CloudPanel's Nginx, Cloudflare) the usual causes are a scheme mismatch or a size limit. Open **F12 → Network**, retry the upload, and read the status of the `upload-file` request — then fix in this order:

1. **403 (invalid signature) — most common.** Laravel saw the request as HTTP and signed the upload URL for the wrong scheme. The app trusts the proxy (`bootstrap/app.php` → `trustProxies`); make sure that commit is **deployed**, set `APP_URL=https://<domain>` in `.env`, then `php8.3 artisan config:cache`. Confirm the Vhost's PHP-FPM block passes `proxy_set_header X-Forwarded-Proto $scheme;` / `fastcgi_param HTTPS "on";`.
2. **413 (too large).** Raise `client_max_body_size 64M;` in the Vhost, and `upload_max_filesize` / `post_max_size` = `64M` in **CloudPanel → PHP Settings**; save (reloads Nginx + FPM).
3. **419 (session/CSRF).** Set `APP_URL` + `SESSION_DOMAIN=<domain>`, then `config:cache`.
4. **500.** Check `storage/logs/laravel.log` — usually storage not writable: `chmod -R ug+rwX storage && chown -R <site-user>:<site-user> storage bootstrap/cache`.

Quick map: **403 → #1**, **413 → #2**, **419 → #3**, **500 → #4**. Start with #1; it resolves the majority of CloudPanel cases.
