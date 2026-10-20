<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Livewire\ShareWebinar;
use App\Models\EmailSend;
use App\Models\Webinar;
use Livewire\Livewire;

/*
 * The "Share Webinar" popup (§7). A visitor emails one person a webinar's
 * details, through the Dispatcher like every other email, with a honeypot and a
 * rate limit because the recipient is not our customer.
 */

it('emails the webinar details to the recipient', function (): void {
    $webinar = Webinar::factory()->scheduled()->create(['title' => 'FMLA Compliance Essentials']);

    Livewire::test(ShareWebinar::class, ['webinar' => $webinar])
        ->set('sharerName', 'Dana Rivera')
        ->set('sharerEmail', 'dana@example.test')
        ->set('recipient', 'colleague@example.test')
        ->set('note', 'Useful for the HR team.')
        ->call('send')
        ->assertSet('sent', true);

    $send = EmailSend::query()->where('email_key', 'marketing.webinar_shared')->sole();

    expect($send->to_email)->toBe('colleague@example.test')
        ->and($send->status)->toBe(EmailStatus::Sent)
        ->and($send->related_id)->toBe($webinar->getKey());
});

it('validates the share form', function (): void {
    $webinar = Webinar::factory()->scheduled()->create();

    Livewire::test(ShareWebinar::class, ['webinar' => $webinar])
        ->set('sharerName', '')
        ->set('sharerEmail', 'not-an-email')
        ->set('recipient', '')
        ->call('send')
        ->assertHasErrors(['sharerName', 'sharerEmail', 'recipient']);

    expect(EmailSend::query()->where('email_key', 'marketing.webinar_shared')->count())->toBe(0);
});

it('sends nothing when the honeypot is filled', function (): void {
    $webinar = Webinar::factory()->scheduled()->create();

    Livewire::test(ShareWebinar::class, ['webinar' => $webinar])
        ->set('sharerName', 'Bot')
        ->set('sharerEmail', 'bot@example.test')
        ->set('recipient', 'victim@example.test')
        ->set('company_website', 'http://spam.test')
        ->call('send')
        ->assertSet('sent', true);

    expect(EmailSend::query()->count())->toBe(0);
});

it('rate limits repeated shares from one visitor', function (): void {
    $webinar = Webinar::factory()->scheduled()->create();

    $share = fn (string $to) => Livewire::test(ShareWebinar::class, ['webinar' => $webinar])
        ->set('sharerName', 'Dana')
        ->set('sharerEmail', 'dana@example.test')
        ->set('recipient', $to)
        ->call('send');

    foreach (['a@example.test', 'b@example.test', 'c@example.test', 'd@example.test', 'e@example.test'] as $to) {
        $share($to);
    }

    // The sixth is refused.
    $share('f@example.test')->assertHasErrors('recipient');

    expect(EmailSend::query()->where('email_key', 'marketing.webinar_shared')->count())->toBe(5);
});
