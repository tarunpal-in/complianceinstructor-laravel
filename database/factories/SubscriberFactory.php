<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    protected $model = Subscriber::class;

    public function definition(): array
    {
        return [
            // Unique, because the column is — a factory handing out a duplicate
            // would fail the insert rather than fail a test usefully.
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'name' => fake()->name(),
            'status' => SubscriberStatus::Subscribed,
            'source' => SubscriberSource::Footer,
            'subscribed_at' => now()->subWeeks(2),
            'confirmed_at' => null,
            'consent_ip' => fake()->ipv4(),
            'consent_user_agent' => fake()->userAgent(),
            'unsubscribed_at' => null,
            'unsubscribe_reason' => null,
            'bounced_at' => null,
            'bounce_reason' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriberStatus::Pending,
            'confirmed_at' => null,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriberStatus::Subscribed,
            'confirmed_at' => now()->subWeeks(2),
        ]);
    }

    public function unsubscribed(?string $reason = null): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriberStatus::Unsubscribed,
            'unsubscribed_at' => now()->subDays(3),
            'unsubscribe_reason' => $reason,
        ]);
    }

    public function bounced(string $reason = 'Mailbox does not exist'): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriberStatus::Bounced,
            'bounced_at' => now()->subDays(5),
            'bounce_reason' => $reason,
        ]);
    }

    /** The one state a form submission may not undo. */
    public function complained(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriberStatus::Complained,
            'unsubscribed_at' => now()->subDays(9),
            'unsubscribe_reason' => 'Reported as spam',
        ]);
    }

    public function from(SubscriberSource $source): static
    {
        return $this->state(fn (): array => ['source' => $source]);
    }

    public function withAddress(string $email): static
    {
        return $this->state(fn (): array => ['email' => Str::lower($email)]);
    }

    /** The same address as an existing account, so the Customer column has something to find. */
    public function matching(User $user): static
    {
        return $this->state(fn (): array => [
            'email' => Str::lower($user->email),
            'name' => $user->name,
        ]);
    }
}
