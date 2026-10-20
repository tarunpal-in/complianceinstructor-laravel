<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAsset;
use App\Models\WebinarHandout;

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

// Handouts (study materials) ---------------------------------------------------

it('lets any entitled learner download a handout', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    $handout = WebinarHandout::factory()->for($webinar)->create(['disk_path' => 'webinar-handouts/slides.pdf']);
    // Handouts come with every format, so a live grant opens them too.
    entitle($user, $webinar, Format::Live);

    $this->actingAs($user)
        ->get(route('learning.handout', $handout))
        ->assertStatus(302);
});

it('forbids a handout to someone with no access to the webinar', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    $handout = WebinarHandout::factory()->for($webinar)->create();

    $this->actingAs($user)
        ->get(route('learning.handout', $handout))
        ->assertForbidden();
});

it('404s an inactive handout', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    $handout = WebinarHandout::factory()->inactive()->for($webinar)->create();
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('learning.handout', $handout))
        ->assertNotFound();
});

it('lists handouts on every learning page, Upcoming included', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create(['scheduled_at' => now()->addWeek()]);
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Live)->create();
    WebinarHandout::factory()->for($webinar)->create(['label' => 'Session Slides']);
    entitle($user, $webinar, Format::Recorded);
    entitle($user, $webinar, Format::Live);

    $this->actingAs($user);

    // Recorded Webinars shows the study material…
    $this->get(route('filament.account.pages.recorded-videos'))
        ->assertSee('Session Slides')
        ->assertSee('Study materials', escape: false);

    // …and Upcoming shows it too: handouts accompany every format a buyer holds,
    // so a live registrant gets them as well.
    $this->get(route('filament.account.pages.upcoming-webinars'))
        ->assertSee('Session Slides')
        ->assertSee('Study materials', escape: false);
});

// The in-site player ----------------------------------------------------------

it('plays a file recording in the in-site player', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create(['title' => 'Watchable Session']);
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->embeddable()
        ->create(['url' => null, 'disk_path' => 'recordings/s1.mp4']);
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('learning.watch', [$webinar->slug, 'recorded']))
        ->assertSuccessful()
        ->assertSee('Watchable Session')
        ->assertSee('<video', escape: false)
        ->assertSee('watch-player', escape: false);
});

it('embeds an externally hosted recording in the player when marked embeddable', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->embeddable()
        ->create(['url' => 'https://stream.test/embed/abc', 'disk_path' => null]);
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('learning.watch', [$webinar->slug, 'recorded']))
        ->assertSuccessful()
        ->assertSee('<iframe', escape: false)
        ->assertSee('https://stream.test/embed/abc', escape: false);
});

it('opens a non-embeddable recording directly instead of the broken player', function (): void {
    // A plain share link (Dropbox and the like) that will not iframe — hitting
    // the watch URL falls back to the access redirect rather than embedding it.
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)
        ->create(['url' => 'https://www.dropbox.com/s/abc/recording', 'disk_path' => null, 'embed' => false]);
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('learning.watch', [$webinar->slug, 'recorded']))
        ->assertRedirect('https://www.dropbox.com/s/abc/recording');
});

it('shows study materials beneath the in-site player', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->embeddable()
        ->create(['url' => null, 'disk_path' => 'recordings/s1.mp4']);
    WebinarHandout::factory()->for($webinar)->create(['label' => 'Watch-along Workbook']);
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('learning.watch', [$webinar->slug, 'recorded']))
        ->assertSuccessful()
        ->assertSee('Study materials')
        ->assertSee('Watch-along Workbook');
});

it('forbids watching a format the learner does not hold', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->embeddable()->create();
    entitle($user, $webinar, Format::Transcript); // holds transcript, not recorded

    $this->actingAs($user)
        ->get(route('learning.watch', [$webinar->slug, 'recorded']))
        ->assertForbidden();
});

it('points an embeddable recording at the in-site player', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->embeddable()->create();
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('filament.account.pages.recorded-videos'))
        ->assertSee(route('learning.watch', [$webinar->slug, 'recorded']), escape: false);
});

it('points a non-embeddable recording at the direct link, not the player', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create();
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)
        ->create(['url' => 'https://www.dropbox.com/s/abc/recording', 'embed' => false]);
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('filament.account.pages.recorded-videos'))
        ->assertSee(route('learning.access', [$webinar->slug, 'recorded']), escape: false)
        ->assertDontSee(route('learning.watch', [$webinar->slug, 'recorded']), escape: false);
});

// The learner pages ------------------------------------------------------------

it('lists a recorded webinar on the Recorded Videos page', function (): void {
    $user = User::factory()->create();
    $webinar = Webinar::factory()->create(['title' => 'Payroll Compliance 2026']);
    WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->create();
    entitle($user, $webinar, Format::Recorded);

    $this->actingAs($user)
        ->get(route('filament.account.pages.recorded-videos'))
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
        ->get(route('filament.account.pages.recorded-videos'))
        ->assertSuccessful()
        ->assertDontSee('Revoked Session');
});

it('shows each format only on its own page', function (): void {
    $user = User::factory()->create();

    $recorded = Webinar::factory()->create(['title' => 'Recorded Only Session']);
    WebinarAsset::factory()->for($recorded)->format(Format::Recorded)->create();
    entitle($user, $recorded, Format::Recorded);

    $transcript = Webinar::factory()->create(['title' => 'Transcript Only Session']);
    WebinarAsset::factory()->for($transcript)->format(Format::Transcript)->create();
    entitle($user, $transcript, Format::Transcript);

    $this->actingAs($user);

    // Recorded page shows the recorded webinar, not the transcript one.
    $this->get(route('filament.account.pages.recorded-videos'))
        ->assertSee('Recorded Only Session')
        ->assertDontSee('Transcript Only Session');

    // Transcripts page shows the transcript webinar, not the recorded one.
    $this->get(route('filament.account.pages.transcripts'))
        ->assertSee('Transcript Only Session')
        ->assertDontSee('Recorded Only Session');
});

it('lists only still-upcoming live sessions on Upcoming Webinars', function (): void {
    $user = User::factory()->create();

    $future = Webinar::factory()->create(['title' => 'Future Live Session', 'scheduled_at' => now()->addWeek()]);
    WebinarAsset::factory()->for($future)->format(Format::Live)->create();
    entitle($user, $future, Format::Live);

    $past = Webinar::factory()->create(['title' => 'Past Live Session', 'scheduled_at' => now()->subWeek()]);
    WebinarAsset::factory()->for($past)->format(Format::Live)->create();
    entitle($user, $past, Format::Live);

    $this->actingAs($user)
        ->get(route('filament.account.pages.upcoming-webinars'))
        ->assertSuccessful()
        ->assertSee('Future Live Session')
        ->assertDontSee('Past Live Session');
});
