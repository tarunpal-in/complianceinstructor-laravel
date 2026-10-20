<?php

declare(strict_types=1);

use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Livewire\ContactForm;
use App\Models\Inquiry;
use Database\Seeders\PageSeeder;
use Livewire\Livewire;

/*
 * The public contact page (§7). It is a page built from the editable `contact`
 * block; the form records an inquiry through the single RecordInquiry intake,
 * fixing the source in code and never trusting a status from the request.
 */

it('serves the seeded contact page with the form and details', function (): void {
    $this->seed(PageSeeder::class);

    $this->get('/contact')
        ->assertSuccessful()
        ->assertSee('Get in touch', escape: false)                    // hero copy
        ->assertSee('Send us a message', escape: false)               // form heading
        ->assertSee('support@complianceinstructor.com', escape: false); // a detail card
});

it('records an inquiry from the contact form', function (): void {
    Livewire::test(ContactForm::class)
        ->set('name', 'Dana Lee')
        ->set('email', 'Dana@Example.com')
        ->set('company', 'Acme HR')
        ->set('subject', 'HRCI credits')
        ->set('message', 'How do my HRCI credits get reported?')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $inquiry = Inquiry::query()->sole();

    expect($inquiry->source)->toBe(InquirySource::Contact)
        ->and($inquiry->status)->toBe(InquiryStatus::New)
        ->and($inquiry->email)->toBe('dana@example.com')   // normalised by the action
        ->and($inquiry->name)->toBe('Dana Lee')
        ->and($inquiry->company)->toBe('Acme HR');
});

it('validates the required contact fields', function (): void {
    Livewire::test(ContactForm::class)
        ->call('submit')
        ->assertHasErrors(['name', 'email', 'message'])
        ->assertSet('sent', false);

    expect(Inquiry::query()->count())->toBe(0);
});

it('rate-limits repeated submissions from one visitor', function (): void {
    $send = fn () => Livewire::test(ContactForm::class)
        ->set('name', 'Spammer')
        ->set('email', 'spam@example.com')
        ->set('message', 'Buy my thing, buy my thing.')
        ->call('submit');

    // Five land, the sixth is turned away.
    foreach (range(1, 5) as $ignored) {
        $send()->assertSet('sent', true);
    }

    $send()->assertHasErrors('message')->assertSet('sent', false);

    expect(Inquiry::query()->count())->toBe(5);
});
