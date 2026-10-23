<?php

declare(strict_types=1);

use App\Actions\Entitlements\GrantEntitlement;
use App\Actions\Entitlements\RevokeEntitlement;
use App\Actions\Webinars\RecordWatchProgress;
use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Models\Accreditation;
use App\Models\Certificate;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarView;
use App\Support\CompletionRules;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * A live webinar that finished long enough ago to be due.
 *
 * @return array{0: Webinar, 1: Accreditation}
 */
function finishedWebinar(int $hoursAgo = 25, int $minutes = 90, string $code = 'HRCI'): array
{
    $webinar = Webinar::factory()->create([
        'title' => 'FMLA Compliance 2026',
        'duration_minutes' => $minutes,
        'scheduled_at' => now()->subHours($hoursAgo),
    ]);

    $accreditation = Accreditation::factory()->create(['code' => $code]);
    $webinar->accreditations()->attach($accreditation->id, ['credits' => null]);

    return [$webinar->fresh(), $accreditation];
}

/** Somebody holding access to a webinar in one format. */
function attendee(Webinar $webinar, Format $format = Format::Live): User
{
    $learner = User::factory()->create();

    app(GrantEntitlement::class)($learner, $webinar, $format, EntitlementSource::Order, 1);

    return $learner;
}

function sweep(): void
{
    Illuminate\Support\Facades\Artisan::call('certificates:issue-due');
}

// The live rule ----------------------------------------------------------------

/**
 * The client's rule, agreed 2026-09-23: holding live access is the
 * qualification, and the certificate arrives 24 hours later without anybody
 * asking for it.
 */
it('certifies live attendees a day after the session, with nobody involved', function (): void {
    [$webinar] = finishedWebinar(hoursAgo: 25);
    $learner = attendee($webinar);

    sweep();

    $certificate = Certificate::query()->where('user_id', $learner->id)->first();

    expect($certificate)->not->toBeNull()
        ->and($certificate?->credits)->toBe('1.50')
        ->and($certificate?->issued_by)->toBeNull();
});

it('waits until the delay has actually passed', function (): void {
    [$webinar] = finishedWebinar(hoursAgo: 23);
    attendee($webinar);

    sweep();

    expect(Certificate::query()->count())->toBe(0);
});

/** §12 — the threshold is the client's to change, not a constant in the code. */
it('follows the delay the client has set', function (): void {
    Setting::set(CompletionRules::LIVE_DELAY_KEY, 1, 'integer');

    [$webinar] = finishedWebinar(hoursAgo: 2);
    attendee($webinar);

    sweep();

    expect(Certificate::query()->count())->toBe(1);
});

/** One certification per body, because they are separate qualifications. */
it('issues one certificate per accrediting body', function (): void {
    [$webinar] = finishedWebinar();
    $shrm = Accreditation::factory()->create(['code' => 'SHRM']);
    $webinar->accreditations()->attach($shrm->id, ['credits' => null]);

    $learner = attendee($webinar->fresh());

    sweep();

    expect(Certificate::query()->where('user_id', $learner->id)->pluck('accreditation_code')->sort()->values()->all())
        ->toBe(['HRCI', 'SHRM']);
});

/** A refund undoes the purchase, and the qualification with it. */
it('skips somebody whose access was taken back', function (): void {
    [$webinar] = finishedWebinar();
    $learner = attendee($webinar);

    $entitlement = $learner->entitlements()->first();
    app(RevokeEntitlement::class)($entitlement, 'Refunded');

    sweep();

    expect(Certificate::query()->count())->toBe(0);
});

it('does not certify people who only bought the recording', function (): void {
    [$webinar] = finishedWebinar();
    attendee($webinar, Format::Recorded);

    sweep();

    expect(Certificate::query()->count())->toBe(0);
});

/** Running twice must not hand anybody a second certificate. */
it('is safe to run over and over', function (): void {
    [$webinar] = finishedWebinar();
    attendee($webinar);

    sweep();
    sweep();
    sweep();

    expect(Certificate::query()->count())->toBe(1);
});

/**
 * No progress marker on the webinar, so somebody who appears late is picked up
 * rather than falling through a gap nobody would notice.
 */
it('picks up a learner who gains access after the first sweep', function (): void {
    [$webinar] = finishedWebinar();

    sweep();
    expect(Certificate::query()->count())->toBe(0);

    attendee($webinar);
    sweep();

    expect(Certificate::query()->count())->toBe(1);
});

/** An administrator withdrew it; the robot does not get to overrule them. */
it('never reissues a certificate somebody revoked', function (): void {
    [$webinar] = finishedWebinar();
    $learner = attendee($webinar);

    sweep();

    app(App\Actions\Certificates\RevokeCertificate::class)(
        Certificate::query()->firstOrFail(),
        'Issued against the wrong session',
    );

    sweep();

    expect(Certificate::query()->count())->toBe(1)
        ->and(Certificate::query()->valid()->count())->toBe(0);
});

it('skips a session too short to earn credits', function (): void {
    [$webinar] = finishedWebinar(minutes: 20);
    attendee($webinar);

    sweep();

    expect(Certificate::query()->count())->toBe(0);
});

it('ignores a webinar with no accreditation at all', function (): void {
    $webinar = Webinar::factory()->create(['scheduled_at' => now()->subDays(2), 'duration_minutes' => 90]);
    attendee($webinar);

    sweep();

    expect(Certificate::query()->count())->toBe(0);
});

// The recorded rule --------------------------------------------------------------

/**
 * "Watched fully, five to ten minutes either way, and not rigid" — so a
 * 90-minute session is finished at 80.
 */
it('certifies a recording watched to within the tolerance', function (): void {
    [$webinar] = finishedWebinar(minutes: 90);
    $learner = attendee($webinar, Format::Recorded);

    app(RecordWatchProgress::class)($learner, $webinar, positionSeconds: 80 * 60);

    expect(Certificate::query()->where('user_id', $learner->id)->count())->toBe(1)
        ->and(WebinarView::query()->first()?->isComplete())->toBeTrue();
});

it('does not certify somebody who stopped well short', function (): void {
    [$webinar] = finishedWebinar(minutes: 90);
    $learner = attendee($webinar, Format::Recorded);

    app(RecordWatchProgress::class)($learner, $webinar, positionSeconds: 40 * 60);

    expect(Certificate::query()->count())->toBe(0)
        ->and(WebinarView::query()->first()?->isComplete())->toBeFalse();
});

/** Scrubbing back to rewatch a section must not undo progress. */
it('never walks the furthest point backwards', function (): void {
    [$webinar] = finishedWebinar(minutes: 90);
    $learner = attendee($webinar, Format::Recorded);

    $record = app(RecordWatchProgress::class);

    $record($learner, $webinar, positionSeconds: 70 * 60);
    $record($learner, $webinar, positionSeconds: 10 * 60);

    expect(WebinarView::query()->first()?->furthest_seconds)->toBe(70 * 60);
});

/**
 * Watching the last ten minutes six times is not watching the session, which is
 * why time accumulated and point reached are kept apart.
 */
it('counts the point reached, not the time spent', function (): void {
    [$webinar] = finishedWebinar(minutes: 90);
    $learner = attendee($webinar, Format::Recorded);

    $record = app(RecordWatchProgress::class);

    foreach (range(1, 6) as $ignored) {
        $record($learner, $webinar, positionSeconds: 15 * 60, watchedSeconds: 15 * 60);
    }

    $view = WebinarView::query()->first();

    expect($view?->seconds_watched)->toBe(90 * 60)
        ->and($view?->furthest_seconds)->toBe(15 * 60)
        ->and(Certificate::query()->count())->toBe(0);
});

/** Finished is finished — no waiting, unlike the live rule. */
it('certifies the moment they reach the end, not on a sweep', function (): void {
    [$webinar] = finishedWebinar(hoursAgo: 1, minutes: 60);
    $learner = attendee($webinar, Format::Recorded);

    app(RecordWatchProgress::class)($learner, $webinar, positionSeconds: 60 * 60);

    expect(Certificate::query()->count())->toBe(1);
});

it('does not certify twice however many reports arrive', function (): void {
    [$webinar] = finishedWebinar(minutes: 60);
    $learner = attendee($webinar, Format::Recorded);

    $record = app(RecordWatchProgress::class);

    $record($learner, $webinar, positionSeconds: 60 * 60);
    $record($learner, $webinar, positionSeconds: 60 * 60);

    $view = WebinarView::query()->first();

    expect(Certificate::query()->count())->toBe(1)
        // Stamped once: "when did they finish it" has one answer.
        ->and($view?->completed_at?->toDateTimeString())
        ->toBe($view?->fresh()?->completed_at?->toDateTimeString());
});

/** §5.2 — a progress report is a claim to have been watching. */
it('refuses progress from somebody with no access', function (): void {
    [$webinar] = finishedWebinar();
    $stranger = User::factory()->create();

    expect(fn () => app(RecordWatchProgress::class)($stranger, $webinar, positionSeconds: 90 * 60))
        ->toThrow(RuntimeException::class, 'has not been made available');

    expect(Certificate::query()->count())->toBe(0);
});

/** The tolerance must not swallow a short session whole. */
it('does not certify somebody five minutes into a short webinar', function (): void {
    [$webinar] = finishedWebinar(minutes: 30);
    $learner = attendee($webinar, Format::Recorded);

    app(RecordWatchProgress::class)($learner, $webinar, positionSeconds: 5 * 60);

    expect(Certificate::query()->count())->toBe(0)
        // A 30-minute session against a 10-minute tolerance needs 20 minutes,
        // never the one-minute floor.
        ->and(CompletionRules::watchedSecondsRequired($webinar))->toBe(20 * 60);
});

it('follows the tolerance the client has set', function (): void {
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, 30, 'integer');

    [$webinar] = finishedWebinar(minutes: 90);
    $learner = attendee($webinar, Format::Recorded);

    app(RecordWatchProgress::class)($learner, $webinar, positionSeconds: 60 * 60);

    expect(Certificate::query()->count())->toBe(1);
});

/** Somebody with live access watching the recording is still watching it. */
it('accepts progress from a live ticket holder', function (): void {
    [$webinar] = finishedWebinar(minutes: 60);
    $learner = attendee($webinar, Format::Live);

    $view = app(RecordWatchProgress::class)($learner, $webinar, positionSeconds: 60 * 60);

    expect($view->isComplete())->toBeTrue();
});

// Progress, in words ---------------------------------------------------------------

it('describes how far through somebody is', function (): void {
    [$webinar] = finishedWebinar(minutes: 90);

    $view = WebinarView::factory()->reached(72 * 60)->make();

    expect($view->percentOf($webinar))->toBe(80)
        ->and($view->describeProgress($webinar))->toBe('1h 12m of 1h 30m');
});

/** A recording can run past the duration somebody typed; 104% reads as a fault. */
it('never reports more than all of it', function (): void {
    [$webinar] = finishedWebinar(minutes: 60);

    expect(WebinarView::factory()->reached(70 * 60)->make()->percentOf($webinar))->toBe(100);
});

// The watch-progress endpoint ------------------------------------------------
// Wires the in-site player to RecordWatchProgress so recorded watch-through
// certificates actually issue from the UI, not just from a direct action call.

it('records progress through the watch endpoint and issues the certificate', function (): void {
    [$webinar] = finishedWebinar(hoursAgo: 1, minutes: 60);
    $learner = attendee($webinar, Format::Recorded);

    $this->actingAs($learner)
        ->postJson(route('learning.watch.progress', $webinar), ['position' => 60 * 60])
        ->assertSuccessful()
        ->assertJson(['completed' => true]);

    expect(Certificate::query()->where('user_id', $learner->id)->count())->toBe(1)
        ->and(WebinarView::query()->first()?->furthest_seconds)->toBe(60 * 60);
});

it('refuses a watch-progress report from a learner with no access (403)', function (): void {
    [$webinar] = finishedWebinar();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('learning.watch.progress', $webinar), ['position' => 90 * 60])
        ->assertForbidden();

    expect(WebinarView::query()->count())->toBe(0)
        ->and(Certificate::query()->count())->toBe(0);
});
