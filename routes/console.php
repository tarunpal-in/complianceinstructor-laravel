<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Refunds sit out a cooling-off window before reaching their gateway, and this
 * is what ends it. Every minute, so "an hour" means an hour rather than an hour
 * and a bit.
 *
 * withoutOverlapping matters more than usual here: two sweeps running together
 * would both find the same due refunds. The action claims each row under a lock
 * as well, so this is the outer of two guards rather than the only one.
 *
 * **This needs cron.** Without `* * * * * php artisan schedule:run` on the
 * server, refunds are agreed, emailed and then never sent — see
 * docs/deployment.md.
 */
Schedule::command('refunds:release-due')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Certifies live attendees once their session is 24 hours behind them — the
 * client's rule, agreed 2026-09-23. Hourly rather than every minute: the delay
 * is measured in hours, so minute precision would be work done for nothing.
 *
 * withoutOverlapping because two sweeps would find the same learners. The
 * action skips anyone already certified as well, so this is the outer of two
 * guards rather than the only one.
 *
 * **This needs cron**, the same `* * * * * php artisan schedule:run` the
 * refunds sweep depends on. Without it, certificates are simply never issued
 * and nobody finds out until a customer asks where theirs is.
 */
Schedule::command('certificates:issue-due')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
