<?php

declare(strict_types=1);

use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Livewire\ContactForm;
use App\Models\Inquiry;
use App\Models\Setting;
use Database\Seeders\CorePageSeeder;
use Livewire\Livewire;

/*
 * The public contact page (§6/§7). It is a core page now — an editable hero over
 * contact details (from the business settings) and the shared Livewire form,
 * which records an inquiry through the single RecordInquiry intake, fixing the
 * source in code and never trusting a status from the request.
 */

it('serves the contact core page with the hero, details and form', function (): void {
    $this->seed(CorePageSeeder::class);
    Setting::set('business.email', 'support@complianceinstructor.com', 'string');
    Setting::set('business.phone', '(800) 555-0142', 'string');

    $this->get('/contact')
        ->assertSuccessful()
        ->assertSee('Get in touch', escape: false)                      // core-page hero
        ->assertSee('Send us a message', escape: false)                 // form heading
        ->assertSee('support@complianceinstructor.com', escape: false)  // detail from settings
        ->assertSee('(800) 555-0142', escape: false);
});

it('renders the seeded contact FAQ as a native-details accordion', function (): void {
    $this->seed(CorePageSeeder::class); // seeds the structured FAQ

    $this->get('/contact')
        ->assertSee('Before you write')
        ->assertSee('How do I access a webinar I bought?')
        ->assertSee('<details class="pb-faq-item">', escape: false); // the accordion markup
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
