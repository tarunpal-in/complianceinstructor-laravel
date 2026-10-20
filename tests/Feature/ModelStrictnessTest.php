<?php

declare(strict_types=1);

use App\Models\Post;
use Illuminate\Database\LazyLoadingViolationException;

/*
 * The lazy-loading violation handler (AppServiceProvider) downgrades a lazy load
 * to a log warning in local/staging so a missing eager load does not 500 an
 * admin screen — but it must stay fatal in tests, which is where §10's N+1
 * guard on the money and access paths is actually enforced. This pins that.
 *
 * Laravel only arms the guard on models hydrated in a collection of more than
 * one row (Builder::hydrate), which is exactly the admin-table N+1 case, so the
 * repro fetches two rows rather than one.
 */

it('still throws on a lazy load during tests', function (): void {
    Post::factory()->count(2)->create();

    // A collection of more than one row, loaded without eager loads: touching a
    // relation on any of them is a guarded lazy load.
    $posts = Post::query()->get();

    expect(fn () => $posts->first()->commentsAwaitingReview)
        ->toThrow(LazyLoadingViolationException::class);
});
