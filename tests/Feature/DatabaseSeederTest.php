<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Webinar;

/*
 * CLAUDE.md §4 rule 1 — `migrate:fresh --seed` must ALWAYS produce a working,
 * demoable app, and CI asserts it. This runs the whole seeder in-suite so the
 * invariant is guarded here too — in particular now that a demo catalogue exists
 * for OrderSeeder to actually sell, a path that had nothing to run against
 * before.
 */
it('runs the full database seeder into a working app', function (): void {
    $this->seed();

    // The catalogue populated the homepage's live sections…
    expect(Webinar::query()->exists())->toBeTrue()
        // …and OrderSeeder had something to sell, so it ran its real path.
        ->and(Order::query()->exists())->toBeTrue();

    $this->get('/')->assertSuccessful()->assertSee('Membership Plans', escape: false);
});
