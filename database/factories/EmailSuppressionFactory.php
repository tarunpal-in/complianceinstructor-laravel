<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SuppressionReason;
use App\Models\EmailSuppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailSuppression>
 */
class EmailSuppressionFactory extends Factory
{
    protected $model = EmailSuppression::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'reason' => SuppressionReason::Bounced,
            'source' => 'Bounce message in the sending mailbox',
            'note' => 'Mailbox does not exist',
            'lifted_at' => null,
        ];
    }

    public function complained(): static
    {
        return $this->state(fn (): array => [
            'reason' => SuppressionReason::Complained,
            'note' => 'Reported a newsletter as spam',
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (): array => [
            'reason' => SuppressionReason::Manual,
            'source' => 'Asked by phone',
            'note' => null,
        ]);
    }

    public function lifted(): static
    {
        return $this->state(fn (): array => [
            'lifted_at' => now(),
            'lifted_reason' => 'Confirmed the address works again',
        ]);
    }
}
