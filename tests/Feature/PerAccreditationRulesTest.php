<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Models\Accreditation;
use App\Models\Certificate;
use App\Models\Entitlement;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarView;
use App\Support\CompletionRules;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A ninety-minute session carrying two bodies' credits. */
function accreditedBy(array $bodies, int $minutes = 90): array
{
    $webinar = Webinar::factory()->create([
        'duration_minutes' => $minutes,
        'scheduled_at' => now()->subDays(3),
    ]);

    $accreditations = [];

    foreach ($bodies as $code => $overrides) {
        $accreditation = Accreditation::factory()->create(['code' => $code, ...$overrides]);
        $webinar->accreditations()->attach($accreditation, ['credits' => '1.50']);
        $accreditations[$code] = $accreditation;
    }

    return [$webinar->fresh(), $accreditations];
}

function learnerWatching(Webinar $webinar, int $furthestSeconds): User
{
    $learner = User::factory()->create();

    Entitlement::factory()->create([
        'user_id' => $learner->getKey(),
        'webinar_id' => $webinar->getKey(),
        'format' => Format::Recorded,
    ]);

    WebinarView::factory()->create([
        'user_id' => $learner->getKey(),
        'webinar_id' => $webinar->getKey(),
        'furthest_seconds' => $furthestSeconds,
    ]);

    return $learner;
}

// Inheritance ------------------------------------------------------------------------

/*
 * Nullable is the whole design. Most bodies are left blank so the client
 * changes one number in one place, and a body added later starts out
 * inheriting rather than with no rule at all.
 */
it('falls back to the global rule when a body sets nothing', function (): void {
    Setting::set(CompletionRules::LIVE_DELAY_KEY, 36, 'integer');
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, 8, 'integer');

    $body = Accreditation::factory()->create([
        'live_delay_hours' => null,
        'watch_tolerance_minutes' => null,
    ]);

    expect($body->liveDelayHours())->toBe(36)
        ->and($body->watchToleranceMinutes())->toBe(8)
        ->and($body->overridesLiveDelay())->toBeFalse()
        ->and($body->overridesWatchTolerance())->toBeFalse();
});

it('uses its own rule where a body sets one', function (): void {
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, 10, 'integer');

    $body = Accreditation::factory()->create(['watch_tolerance_minutes' => 5]);

    expect($body->watchToleranceMinutes())->toBe(5)
        ->and($body->overridesWatchTolerance())->toBeTrue();
});

it('requires more of a recording for a stricter body', function (): void {
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, 10, 'integer');

    $webinar = Webinar::factory()->create(['duration_minutes' => 90]);
    $strict = Accreditation::factory()->create(['watch_tolerance_minutes' => 5]);

    expect(CompletionRules::watchedSecondsRequired($webinar))->toBe(80 * 60)
        ->and(CompletionRules::watchedSecondsRequired($webinar, $strict))->toBe(85 * 60);
});

// The case this exists for ---------------------------------------------------------------

/*
 * §12's requirement, in one test. One webinar, two bodies, one learner: HRCI
 * allows five minutes skipped and SHRM allows ten, so watching eighty-two of
 * ninety minutes earns one certificate and not the other. Under a single global
 * tolerance the learner received both — an HRCI certificate that should not
 * exist.
 */
it('issues one body certificate and withholds another from the same session', function (): void {
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, 10, 'integer');

    [$webinar] = accreditedBy([
        'HRCI' => ['watch_tolerance_minutes' => 5],
        'SHRM' => ['watch_tolerance_minutes' => null],
    ]);

    $learner = learnerWatching($webinar, 82 * 60);

    app(App\Actions\Certificates\IssueEarnedCertificates::class)($learner, $webinar);

    $codes = Certificate::query()->where('user_id', $learner->getKey())->pluck('accreditation_code');

    expect($codes->all())->toBe(['SHRM']);
});

/** And the strict body's certificate arrives once they have watched further. */
it('issues the stricter body later, when they have watched enough for it', function (): void {
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, 10, 'integer');

    [$webinar] = accreditedBy([
        'HRCI' => ['watch_tolerance_minutes' => 5],
        'SHRM' => ['watch_tolerance_minutes' => null],
    ]);

    $learner = learnerWatching($webinar, 82 * 60);
    $issue = app(App\Actions\Certificates\IssueEarnedCertificates::class);

    $issue($learner, $webinar);

    WebinarView::query()
        ->where('user_id', $learner->getKey())
        ->update(['furthest_seconds' => 86 * 60]);

    $issue($learner, $webinar);

    expect(Certificate::query()->where('user_id', $learner->getKey())->pluck('accreditation_code')->sort()->values()->all())
        ->toBe(['HRCI', 'SHRM']);
});

// Live delays ------------------------------------------------------------------------------

it('holds a slower body back while a faster one is already due', function (): void {
    Setting::set(CompletionRules::LIVE_DELAY_KEY, 24, 'integer');

    [$webinar] = accreditedBy([
        'SHRM' => ['live_delay_hours' => null],
        'HRCI' => ['live_delay_hours' => 96],
    ]);

    // Three days after the session: past SHRM's 24 hours, short of HRCI's 96.
    $learner = User::factory()->create();
    Entitlement::factory()->create([
        'user_id' => $learner->getKey(),
        'webinar_id' => $webinar->getKey(),
        'format' => Format::Live,
    ]);

    app(App\Actions\Certificates\IssueEarnedCertificates::class)($learner, $webinar);

    expect(Certificate::query()->where('user_id', $learner->getKey())->pluck('accreditation_code')->all())
        ->toBe(['SHRM']);
});

/*
 * The sweep looks for webinars whose delay has elapsed, and "elapsed" is no
 * longer one number. Taking the shortest means it looks early and the per-body
 * check decides; taking the longest would hold every body back waiting for the
 * slowest.
 */
it('looks as early as the quickest body requires', function (): void {
    Setting::set(CompletionRules::LIVE_DELAY_KEY, 24, 'integer');

    Accreditation::factory()->create(['code' => 'HRCI', 'live_delay_hours' => 96]);
    Accreditation::factory()->create(['code' => 'CPE', 'live_delay_hours' => 6]);

    expect(Accreditation::shortestLiveDelayHours())->toBe(6);
});

it('falls back to the global delay when no body overrides it', function (): void {
    Setting::set(CompletionRules::LIVE_DELAY_KEY, 24, 'integer');

    Accreditation::factory()->create(['live_delay_hours' => null]);

    expect(Accreditation::shortestLiveDelayHours())->toBe(24);
});

/** With no accreditations at all, min() over an empty spread would have thrown. */
it('answers the shortest delay on an empty catalogue', function (): void {
    Setting::set(CompletionRules::LIVE_DELAY_KEY, 24, 'integer');

    expect(Accreditation::shortestLiveDelayHours())->toBe(24);
});

// Qualifying ----------------------------------------------------------------------------------

it('lets either route earn a certificate', function (): void {
    [$webinar, $bodies] = accreditedBy(['HRCI' => []]);
    $hrci = $bodies['HRCI'];

    expect(CompletionRules::qualifiesFor($webinar, $hrci, holdsLiveAccess: true, furthestSeconds: null))->toBeTrue()
        ->and(CompletionRules::qualifiesFor($webinar, $hrci, holdsLiveAccess: false, furthestSeconds: 90 * 60))->toBeTrue()
        ->and(CompletionRules::qualifiesFor($webinar, $hrci, holdsLiveAccess: false, furthestSeconds: 10))->toBeFalse();
});

/** A webinar with no date has no live session to have attended. */
it('does not treat an undated webinar as a live session that has passed', function (): void {
    $webinar = Webinar::factory()->create(['scheduled_at' => null, 'duration_minutes' => 90]);
    $body = Accreditation::factory()->create();

    expect(CompletionRules::qualifiesFor($webinar, $body, holdsLiveAccess: true, furthestSeconds: null))->toBeFalse();
});

// The admin screen ------------------------------------------------------------------------------

it('describes what a body inherits and what it sets', function (): void {
    Setting::set(CompletionRules::LIVE_DELAY_KEY, 24, 'integer');
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, 10, 'integer');

    $body = Accreditation::factory()->create([
        'live_delay_hours' => 96,
        'watch_tolerance_minutes' => null,
    ]);

    expect($body->describeRules())
        ->toContain('96 hours')
        ->toContain('10 (inherited) minutes');
});

it('accepts the rules on the accreditation form', function (): void {
    $this->actingAs(userWithRole(App\Enums\UserRole::SuperAdmin));

    Livewire\Livewire::test(App\Filament\Resources\Accreditations\Pages\CreateAccreditation::class)
        ->fillForm([
            'code' => 'NASBA',
            'name' => 'National Association of State Boards of Accountancy',
            'live_delay_hours' => 48,
            'watch_tolerance_minutes' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $body = Accreditation::query()->where('code', 'NASBA')->sole();

    expect($body->liveDelayHours())->toBe(48)
        ->and($body->watchToleranceMinutes())->toBe(2);
});

it('leaves the rules blank when nothing is typed', function (): void {
    $this->actingAs(userWithRole(App\Enums\UserRole::SuperAdmin));

    Livewire\Livewire::test(App\Filament\Resources\Accreditations\Pages\CreateAccreditation::class)
        ->fillForm(['code' => 'APA', 'name' => 'American Payroll Association'])
        ->call('create')
        ->assertHasNoFormErrors();

    $body = Accreditation::query()->where('code', 'APA')->sole();

    expect($body->live_delay_hours)->toBeNull()
        ->and($body->watch_tolerance_minutes)->toBeNull()
        // ...and therefore follows the default rather than having no rule.
        ->and($body->liveDelayHours())->toBe(CompletionRules::DEFAULT_LIVE_DELAY_HOURS);
});
