# Deploying to staging — GitHub → Ubuntu 24.04 + CloudPanel

Runbook for getting this repository onto a staging server. Written against the
state of the project at the first staging deploy: Laravel 13, PHP 8.3+,
Filament 5, Vite 8, Horizon, Redis.

Read §0 before starting. Steps assume you are the server's root user unless a
step says otherwise.

---

## 0. Before you start

**What you need**

| | |
|---|---|
| VPS | Ubuntu 24.04 LTS, 4 vCPU / 8 GB to start (CLAUDE.md §11). CloudPanel needs 2 GB minimum; 8 GB gives Redis, MySQL and Horizon room |
| Domain | A subdomain pointed at the VPS, e.g. `staging.complianceinstructor.com`, A record → server IP |
| GitHub | An account, and an **empty private repository** |
| Local | This repo, all work committed |

**Two things specific to this project**

1. **`public/build` is git-ignored**, so compiled CSS/JS is not in the
   repository. Assets are built on the server, which means Node must be
   installed there. §8 covers moving that into CI later.
2. **Staging is the first time these migrations run on MySQL 8.** Local
   development uses XAMPP's MariaDB 10.4; CI runs MySQL 8.4. Any engine
   difference — JSON column handling, collation, strict-mode rejections — will
   surface here first. Run §5.6 with the output visible rather than piping it
   away.

**Never commit `.env`.** It is git-ignored (CLAUDE.md §9) and stays that way.
Secrets are typed on the server, not pushed.

---

## 1. Push to GitHub

### 1.1 Create the repository

On GitHub: **New repository** → name it `complianceinstructor` → **Private** →
do **not** add a README, .gitignore or licence. An empty repo avoids a merge on
first push.

### 1.2 Confirm no secrets are staged

Run this before pushing. It should print nothing:

```bash
git ls-files | grep -E '^\.env$|\.pem$|\.key$'
```

If `.env` appears, stop and remove it from tracking:

```bash
git rm --cached .env && git commit -m "Stop tracking .env"
```

### 1.3 Add the remote and push

```bash
git remote add origin git@github.com:<your-account>/complianceinstructor.git
git push -u origin main
```

Using SSH (`git@github.com:`) rather than HTTPS means no password prompts on the
server later. If you do not have an SSH key on GitHub yet, use the HTTPS URL for
this push and set up a deploy key in §5.2.

### 1.4 Verify

```bash
git remote -v
git log origin/main --oneline -1
```

---

## 2. Install CloudPanel

SSH to the server as root.

```bash
apt update && apt -y upgrade
```

Install CloudPanel with **MySQL 8** — CLAUDE.md §3 specifies MySQL 8 to match
the client's hosting, and the default engine differs by installer version, so
state it explicitly:

```bash
curl -sS https://installer.cloudpanel.io/ce/v2/install.sh -o install.sh
echo "a3ba15c8b9c4f1a5e1f1e0e9b0d5ec9a5d1f0b1a2c3d4e5f6a7b8c9d0e1f2a3b  install.sh" | sha256sum -c
bash install.sh --db-engine=MYSQL_8.0
```

> The checksum above is illustrative. Take the current one from
> <https://www.cloudpanel.io/docs/v2/getting-started/installation/> and verify
> before running — you are piping an installer to a shell as root.

The installer takes a few minutes and reboots nothing. When it finishes it
prints an admin URL:

```
https://<server-ip>:8443
```

Open it, accept the self-signed certificate warning, and create the admin user.

### 2.1 Point the firewall at the right ports

CloudPanel manages its own firewall rules. Confirm 22, 80, 443 and 8443 are
open:

```bash
clpctl cloudflare:update:ips   # only if you front the site with Cloudflare
ufw status
```

---

## 3. Create the site

### 3.1 Add a PHP site

In CloudPanel: **Sites → Add Site → Create a PHP Site**.

| Field | Value |
|---|---|
| Domain Name | `staging.complianceinstructor.com` |
| Vhost Template | **Laravel 12** (or the newest Laravel entry) |
| PHP Version | **8.3** |
| Site User | `ci-staging` |
| Site User Password | generate and store it |

Picking the Laravel template matters: it sets the document root to `public/` and
adds the `try_files` rule Laravel needs. CLAUDE.md §11 calls out the predecessor
serving its whole project tree from the web root — the template is what prevents
that.

Equivalent CLI:

```bash
clpctl site:add:php \
  --domainName='staging.complianceinstructor.com' \
  --phpVersion='8.3' \
  --vhostTemplate='Laravel 12' \
  --siteUser='ci-staging' \
  --siteUserPassword='<generated>'
```

The site root is now `/home/ci-staging/htdocs/staging.complianceinstructor.com`.

### 3.2 Create the database

**Databases → Add Database**, or:

```bash
clpctl db:add \
  --domainName='staging.complianceinstructor.com' \
  --databaseName='ci_staging' \
  --databaseUserName='ci_staging' \
  --databaseUserPassword='<generated>'
```

Store the name, user and password — §5.3 needs them.

### 3.3 Issue a TLS certificate

DNS must already resolve to the server:

```bash
clpctl lets-encrypt:install:certificate --domainName='staging.complianceinstructor.com'
```

---

## 4. Server dependencies

CloudPanel gives you Nginx, PHP-FPM and MySQL. Four things this project needs
that it does not install.

### 4.1 Redis

`.env.example` sets cache, session and queue to Redis (CLAUDE.md §3 — the
predecessor ran all three on MySQL and hit write contention on launch spikes).

```bash
apt -y install redis-server
systemctl enable --now redis-server
redis-cli ping          # expect: PONG
```

Bind Redis to localhost only — it must never be reachable from the internet:

```bash
grep -E '^bind|^protected-mode' /etc/redis/redis.conf
# want: bind 127.0.0.1 -::1
#       protected-mode yes
```

### 4.2 The PHP Redis extension

```bash
apt -y install php8.3-redis
systemctl restart php8.3-fpm
php8.3 -m | grep -x redis     # expect: redis
```

### 4.3 Supervisor, for Horizon

```bash
apt -y install supervisor
systemctl enable --now supervisor
```

### 4.4 Node, to build assets

Vite 8 needs Node 20.19+ or 22.12+. Node 22 LTS:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt -y install nodejs
node -v && npm -v
```

### 4.5 Confirm the PHP extensions Laravel needs

```bash
php8.3 -m | grep -E '^(pdo_mysql|mbstring|openssl|tokenizer|xml|ctype|bcmath|curl|fileinfo|zip|gd|intl|exif|sodium|redis|pcntl|posix)$' | sort
```

All sixteen should list. `pcntl` and `posix` are what Horizon needs and what
Windows cannot provide — this is the first environment where Horizon actually
runs. Install anything missing with `apt -y install php8.3-<name>`.

---

## 5. Deploy the application

**Everything in this section runs as the site user, not root.** Files owned by
root break PHP-FPM, which runs as `ci-staging`.

```bash
su - ci-staging
cd ~/htdocs/staging.complianceinstructor.com
```

### 5.1 Clear the directory

CloudPanel leaves a placeholder `index.php`:

```bash
rm -rf ./* ./.??*
```

### 5.2 Clone

If you pushed over SSH, give the server a deploy key first:

```bash
ssh-keygen -t ed25519 -C "ci-staging@staging" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Add that key to GitHub under **Settings → Deploy keys** on the repository, read-only.
Then:

```bash
git clone git@github.com:<your-account>/complianceinstructor.git .
```

(HTTPS with a personal access token also works, but a read-only deploy key is
narrower — it grants one repository, not your whole account.)

### 5.3 Configure the environment

```bash
cp .env.example .env
nano .env
```

Set at minimum:

```dotenv
APP_NAME="Compliance Instructor"
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging.complianceinstructor.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ci_staging
DB_USERNAME=ci_staging
DB_PASSWORD=<from 3.2>

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

MAIL_MAILER=log
```

`APP_DEBUG=false` is not optional. CLAUDE.md §9 lists the predecessor shipping
`APP_DEBUG=true` with live credentials as a Critical finding, and §14 #16 bans
returning exception messages as responses.

`DB_CONNECTION=mysql`, not `mariadb` — local development uses the MariaDB
driver; the server is MySQL 8.

Leave Stripe, PayPal and Sentry blank until those accounts exist. The setup
checklist on the admin dashboard tracks them.

### 5.4 Install dependencies

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

`--no-dev` omits Pest, Larastan, Pint and Debugbar — none belong on a server.

### 5.5 Generate the application key

```bash
php artisan key:generate --force
```

One key per environment. Never copy the local one: it decrypts sessions and any
encrypted column.

### 5.6 Migrate and seed

This is the step to watch. It is the first run of these migrations on MySQL 8:

```bash
php artisan migrate --force --seed
```

CLAUDE.md §11 wants staging carrying seeded data for client review, so `--seed`
is deliberate. It creates the four roles, the permission set, an admin user and
the promo bar defaults.

If a migration fails on a MySQL/MariaDB difference, capture the full error — it
is a real schema bug that would otherwise have surfaced in production.

### 5.7 Build assets

```bash
npm ci
npm run build
```

`npm ci` rather than `npm install`, so the lockfile is honoured exactly.

The build writes `public/build/`, which is git-ignored — so this step is
required on every deploy that touches CSS or JS.

### 5.8 Link storage

```bash
php artisan storage:link
```

### 5.9 Permissions

```bash
chmod -R ug+rwX storage bootstrap/cache
```

Do not `chown www-data`. CloudPanel runs PHP-FPM as the site user, so files
already have the right owner — changing it breaks writes.

### 5.10 Cache for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize
```

`filament:optimize` caches Filament's component discovery. Worth knowing: a
stale version of that cache makes new admin pages return 404 while the class
loads fine — if that happens, `php artisan filament:optimize-clear`.

### 5.11 Check it

Visit `https://staging.complianceinstructor.com`. Then the admin at `/admin`,
signing in with the seeded credentials.

---

## 6. Queue worker and scheduler

### 6.1 Horizon under Supervisor

As **root**, create `/etc/supervisor/conf.d/ci-staging-horizon.conf`:

```ini
[program:ci-staging-horizon]
process_name=%(program_name)s
command=php /home/ci-staging/htdocs/staging.complianceinstructor.com/artisan horizon
autostart=true
autorestart=true
user=ci-staging
redirect_stderr=true
stdout_logfile=/home/ci-staging/logs/horizon.log
stopwaitsecs=3600
```

`stopwaitsecs=3600` lets a long job — a PDF, an export — finish rather than
being killed mid-write.

```bash
mkdir -p /home/ci-staging/logs && chown ci-staging:ci-staging /home/ci-staging/logs
supervisorctl reread && supervisorctl update
supervisorctl status ci-staging-horizon
```

The dashboard is at `/horizon`, and is gated by the `HorizonServiceProvider`
gate — it should not be publicly reachable.

### 6.2 The scheduler

As the **site user**, `crontab -e`:

```cron
* * * * * cd /home/ci-staging/htdocs/staging.complianceinstructor.com && php artisan schedule:run >> /dev/null 2>&1
```

**Do not skip this on a server that takes payments.** Refunds sit out a
cooling-off window before they reach Stripe or PayPal, and `refunds:release-due`
— scheduled every minute — is what ends it. Without cron a refund is agreed,
every administrator is emailed to say it is going out, and then it never does.
The customer chases it days later and the record says it was issued.

Check it is running after any server move:

```bash
php artisan schedule:list          # refunds:release-due should be listed
php artisan refunds:release-due    # safe to run by hand; it is idempotent
```

---

## 7. Lock staging down

**This matters more than usual here.** CLAUDE.md §3 notes the business "lives
on" SEO. An indexable staging site is duplicate content competing with the real
one, and it is how unfinished pages end up in search results.

### 7.1 HTTP basic auth

CloudPanel: **Sites → your site → Security → Basic Auth** → add a user.

That single switch also keeps crawlers out entirely, which is the most reliable
protection.

### 7.2 Tell crawlers anyway

Belt and braces, in case basic auth is ever lifted for a client review. Add to
the site's Nginx vhost (**Sites → Vhost** in CloudPanel), inside `server { }`:

```nginx
add_header X-Robots-Tag "noindex, nofollow" always;
```

A header rather than `robots.txt`, because a header applies to every response
including ones a crawler reaches directly.

---

## 8. Subsequent deploys

Create `~/deploy.sh` as the site user:

```bash
#!/usr/bin/env bash
set -euo pipefail

cd /home/ci-staging/htdocs/staging.complianceinstructor.com

php artisan down --render="errors::503" || true

git pull origin main

composer install --no-dev --optimize-autoloader --no-interaction

php artisan migrate --force

npm ci
npm run build

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize

php artisan up

# Horizon must be restarted, not reloaded: workers hold the old code in memory
# until they are told to quit.
php artisan horizon:terminate

echo "Deployed $(git rev-parse --short HEAD)"
```

```bash
chmod +x ~/deploy.sh
~/deploy.sh
```

`horizon:terminate` is the line people forget. Without it, queued jobs keep
running the previous release's code until the workers happen to restart.

### What this is not

This is a **staging** deploy: `git pull` in place, with a maintenance window of
a few seconds. CLAUDE.md §11 specifies something stronger for production — a
GitHub Actions pipeline running Pint, Larastan and Pest, then building an atomic
release directory and switching a symlink, so a rollback is a symlink switch and
there is no downtime. Build that before the first production deploy, not before
the first staging one.

---

## 9. Optional — deploy on push

Once staging is stable, a workflow can run `deploy.sh` on every push to `main`.

Add these repository secrets (**Settings → Secrets and variables → Actions**):

| Secret | Value |
|---|---|
| `SSH_HOST` | server IP |
| `SSH_USER` | `ci-staging` |
| `SSH_KEY` | a private key whose public half is in `~/.ssh/authorized_keys` on the server |

`.github/workflows/deploy-staging.yml`:

```yaml
name: Deploy staging

on:
  push:
    branches: [main]

jobs:
  deploy:
    # Only deploy what already passed. `ci` is the workflow name in ci.yml.
    needs: []
    runs-on: ubuntu-24.04
    steps:
      - uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.SSH_HOST }}
          username: ${{ secrets.SSH_USER }}
          key: ${{ secrets.SSH_KEY }}
          script: ~/deploy.sh
```

Gate it behind CI passing before you rely on it — deploying a red build to a
server the client is reviewing is worse than deploying nothing.

---

## Troubleshooting

**500 with a blank page**
`tail -50 storage/logs/laravel.log`. With `APP_DEBUG=false` the error is in the
log, not the response — which is the point.

**419 on any form**
Session driver cannot reach Redis. `redis-cli ping`, then check `REDIS_HOST` is
`127.0.0.1`.

**A new admin page 404s but the class loads**
Stale Filament component cache. `php artisan filament:optimize-clear`.

**Unstyled site, or 404s on `/build/...`**
`npm run build` did not run, or ran as root and the site user cannot read the
output. Rebuild as the site user.

**`MissingAttributeException` on a column that exists**
A migration has not run. `php artisan migrate --force`.

**Changes to `.env` do nothing**
`config:cache` froze the old values. `php artisan config:clear`, then re-cache.

**Horizon shows nothing**
`supervisorctl status ci-staging-horizon`, then
`tail -50 /home/ci-staging/logs/horizon.log`.

---

## Deferred

Not needed for the first staging deploy; note them so they are not forgotten.

| Item | When |
|---|---|
| Headless Chrome for `spatie/laravel-pdf` — invoices, certificates. Install Chromium, set `BROWSERSHOT_CHROME_PATH` | P3 (invoices) |
| Transactional mail — Postmark or SES with SPF, DKIM, DMARC. `MAIL_MAILER=log` until then | P3 |
| Sentry — set `SENTRY_LARAVEL_DSN` | P9, or sooner |
| `spatie/laravel-backup` to off-site storage, with a rehearsed restore | P9 (§11) |
| Atomic zero-downtime releases and rollback by symlink | before first production deploy (§11) |
| S3-compatible object storage for uploads and generated PDFs | P5 |
