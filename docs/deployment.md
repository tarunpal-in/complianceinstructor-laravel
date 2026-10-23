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
- *Uploads fail in admin* → confirm Redis + the Livewire temp disk are writable and R2 creds are valid.
- *Queued jobs not running* → `supervisorctl status`; `php8.3 artisan horizon:status`.
- *PDFs blank/failing* → Chromium not installed or not found by Browsershot; either install `chromium-browser` (step 2) or confirm the dompdf fallback is active.
- *Permissions* → `chmod -R ug+rwX storage bootstrap/cache` as `<site-user>`.
