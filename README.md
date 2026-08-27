# Compliance Instructor

A continuing-education (CEU) training platform selling professional-development
webinars and memberships to a business audience.

See [CLAUDE.md](CLAUDE.md) for the project brief, architecture decisions and
working agreement. It is the single source of truth — read it before writing code.

## Requirements

| | |
|---|---|
| PHP | 8.3+ (`C:\php-83` locally — **not** XAMPP's bundled 8.2) |
| Database | MySQL 8 in production; MariaDB 10.4 via XAMPP locally |
| Node | 20+ |

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Start MariaDB from the XAMPP Control Panel first (MySQL → Start).

- App — http://localhost:8000
- Admin — http://localhost:8000/admin

Queues run with `php artisan queue:work` locally. Horizon needs `ext-pcntl`
and `ext-posix`, which do not exist on Windows; it runs in production only.

## Quality gates

```bash
composer lint     # Pint, writes
composer types    # Larastan level 6
composer test     # Pest
composer ci       # everything CI runs, in order
```

CI additionally runs `migrate:fresh --seed` against **MySQL 8.4**, which is what
catches anything that works on MariaDB locally but not on the production engine.
