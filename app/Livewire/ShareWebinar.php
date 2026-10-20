<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Email\Dispatcher;
use App\Mail\WebinarShared;
use App\Models\Webinar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The "Share Webinar" popup on the detail page (§7 engagement).
 *
 * A visitor emails one person the details of a webinar. The recipient is not our
 * customer, so this is a potential spam relay: it is rate limited by IP, carries
 * a honeypot, and the message goes through the Dispatcher like every other email
 * — so the send budget and the suppression list still apply. No price or access
 * is touched; it only sends a link.
 */
class ShareWebinar extends Component
{
    public Webinar $webinar;

    public string $sharerName = '';

    public string $sharerEmail = '';

    public string $recipient = '';

    public string $note = '';

    /** Honeypot — a real person leaves it empty; a bot fills it. */
    public string $company_website = '';

    public bool $sent = false;

    public function send(Dispatcher $dispatcher): void
    {
        // A filled honeypot is a bot: look successful, send nothing.
        if ($this->company_website !== '') {
            $this->sent = true;

            return;
        }

        $data = $this->validate([
            'sharerName' => ['required', 'string', 'max:120'],
            'sharerEmail' => ['required', 'email', 'max:255'],
            'recipient' => ['required', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'sharerName' => 'your name',
            'sharerEmail' => 'your email',
            'recipient' => "recipient's email",
        ]);

        $key = 'share-webinar:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'recipient' => 'You have shared a few webinars already. Please try again in a minute.',
            ]);
        }

        RateLimiter::hit($key, 60);

        $dispatcher->send(new WebinarShared(
            $this->webinar,
            $data['sharerName'],
            $data['sharerEmail'],
            $data['recipient'],
            $data['note'] !== '' ? $data['note'] : null,
        ));

        $this->sent = true;
        $this->reset(['sharerName', 'sharerEmail', 'recipient', 'note']);
    }

    public function resetForm(): void
    {
        $this->reset(['sharerName', 'sharerEmail', 'recipient', 'note', 'sent']);
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.share-webinar');
    }
}
