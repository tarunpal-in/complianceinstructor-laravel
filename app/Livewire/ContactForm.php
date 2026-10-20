<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Inquiries\RecordInquiry;
use App\Enums\InquirySource;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * The public contact form — §7 (contact & inquiry handling).
 *
 * Collects a message and hands it to the one inquiry intake (RecordInquiry),
 * which decides what an inquiry starts as (§4 rule 2 / §14 #9). Nothing here is
 * trusted beyond the typed fields: the source is fixed to Contact in code, and
 * the status is set by the action, never by the form (§14 #2 with the stakes
 * lowered). Rate-limited by IP (§9 — rate limiting on public forms).
 *
 * The visible copy (heading, button, success line) is passed in from the
 * editable `contact` block, so the whole page stays admin-editable.
 */
class ContactForm extends Component
{
    public ?string $formHeading = null;

    public ?string $buttonLabel = null;

    public ?string $successMessage = null;

    public string $name = '';

    public string $email = '';

    public string $company = '';

    public string $phone = '';

    public string $subject = '';

    public string $message = '';

    public bool $sent = false;

    public function mount(): void
    {
        $this->formHeading ??= 'Send us a message';
        $this->buttonLabel ??= 'Send message';
        $this->successMessage ??= 'Thanks — your message is on its way. We will be in touch shortly.';
    }

    public function submit(RecordInquiry $record): void
    {
        $key = 'contact:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            $this->addError('message', 'Too many messages just now. Please try again in a few minutes.');

            return;
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        RateLimiter::hit($key, decaySeconds: 600);

        $record(
            name: $data['name'],
            email: $data['email'],
            source: InquirySource::Contact,
            subject: $data['subject'] ?: null,
            message: $data['message'],
            company: $data['company'] ?: null,
            phone: $data['phone'] ?: null,
            request: request(),
        );

        $this->reset(['name', 'email', 'company', 'phone', 'subject', 'message']);
        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.contact-form');
    }
}
