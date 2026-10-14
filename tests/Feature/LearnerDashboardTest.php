<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAsset;

/*
 * The learner dashboard and its access gate — §5.2 / §9 / P5 slice 1. Access is
 * decided only by the entitlement ledger; the file itself is never exposed by a
 * guessable path.
 */

function entitle(User $user, Webinar $webinar, Format $format): void
{
    Entitlement::factory()->create([
        'user_id' => $user->getKey(),
        'webinar_id' => $webinar->getKey(),
        'format' => $format,
    ]);
}

// The access gate --------------------------------------------------------------

it('sends an entitled learner to an externally hosted asset', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)
        ->create(['url' => 'https://videos.test/recording-1', 'disk_path' => null]);
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('learning.access', [$webinar->slug, 'recorded']))
        ->assertRedirect('https://videos.test/recording-1');
});

it('sends an entitled learner to a signed URL for a file on the private disk', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Transcript)
        ->create(['url' => null, 'disk_path' => 'transcripts/session.pdf']);
    entitle($user, $webinar, Format::Transcript);

    // A redirect to a minted URL — never the raw disk path.
    $this->actingAs($user)
        ->get(route('learning.access', [$webinar->slug, 'transcript']))
        ->assertStatus(302);
});

it('forbids a format the learner does not hold', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->create();
    entitle($user, $webinar, Format::Transcript); // holds transcript, not recorded

    $this->actingAs($user)
        ->get(route('learning.access', [$webinar->slug, 'recorded']))
        ->assertForbidden();
});

it('404s an unknown format', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();

    $this->actingAs($user)
        ->get(route('learning.access', [$webinar->slug, 'hologram']))
        ->assertNotFound();
});

it('withholds a join link until the session is released', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Live)
        ->releasedAt(now()->addDays(3))
        ->create(['url' => 'https://zoom.test/join', 'disk_path' => null]);
    entitle($user, $webinar, Format::Live);

    $this->actingAs($user)
        ->get(route('learning.access', [$webinar->slug, 'live']))
        ->assertNotFound();
});

it('sends a guest to log in', function (): void {
    $webinar = Webinar::factory()->create();

    $this->get(route('learning.access', [$webinar->slug, 'recorded']))
        ->assertRedirect(route('login'));
});

// The dashboard page -----------------------------------------------------------

it('lists the learner their entitled webinars', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create(['title' => 'Payroll Compliance 2026']);
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->create();
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('filament.account.pages.my-learning'))
        ->assertSuccessful()
        ->assertSee('Payroll Compliance 2026');
});

it('does not list a webinar whose entitlement was revoked', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create(['title' => 'Revoked Session']);
    Entitlement::factory()->create([
        'user_id' => $user->getKey(),
        'webinar_id' => $webinar->getKey(),
        'format' => Format::Recorded,
        'revoked_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('filament.account.pages.my-learning'))
        ->assertSuccessful()
        ->assertDontSee('Revoked Session');
});
