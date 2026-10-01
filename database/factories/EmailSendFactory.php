<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailCategory;
use App\Enums\EmailStatus;
use App\Mail\RefundIssued;
use App\Models\EmailSend;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailSend>
 */
class EmailSendFactory extends Factory
{
    protected $model = EmailSend::class;

    /**
     * Defaults to a real catalogue entry rather than a made-up key.
     *
     * A factory producing `email_key` values the catalogue does not know would
     * exercise only the fallback path — the one the screen takes for a retired
     * email — and never the one every row actually takes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'email_key' => 'purchase.refund_issued',
            'category' => EmailCategory::Purchase,
            'mailable' => RefundIssued::class,
            'payload' => [],
            'to_email' => $this->faker->unique()->safeEmail(),
            'to_name' => $this->faker->name(),
            'subject' => 'Your refund has been sent',
            'body_html' => '<p>We have refunded your order.</p>',
            'status' => EmailStatus::Sent,
            'mailer' => 'smtp',
            'message_id' => '<'.Str::uuid().'@complianceinstructor.test>',
            'queued_at' => now(),
            'sent_at' => now(),
            'attempts' => 1,
        ];
    }

    public function failed(string $error = 'Connection to smtp.gmail.com timed out.'): static
    {
        return $this->state(fn (): array => [
            'status' => EmailStatus::Failed,
            'sent_at' => null,
            'failed_at' => now(),
            'message_id' => null,
            'error' => $error,
            'attempts' => 3,
        ]);
    }

    public function deferred(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailStatus::Deferred,
            'sent_at' => null,
            'message_id' => null,
            'outcome_note' => 'The 24-hour bulk allowance of 300 is spent (300 sent).',
            'attempts' => 0,
        ]);
    }

    public function suppressed(string $note = 'This address is held back: Bouncing.'): static
    {
        return $this->state(fn (): array => [
            'status' => EmailStatus::Suppressed,
            'sent_at' => null,
            'message_id' => null,
            'outcome_note' => $note,
            'attempts' => 0,
        ]);
    }

    /** A send whose body has aged out, so the screen has the pruned state to show. */
    public function pruned(): static
    {
        return $this->state(fn (): array => [
            'body_html' => null,
            'body_pruned_at' => now(),
        ]);
    }

    public function bulk(): static
    {
        return $this->state(fn (): array => [
            'email_key' => 'delivery.recording_ready',
            'category' => EmailCategory::Delivery,
            'subject' => 'Your recording is ready',
        ]);
    }
}
