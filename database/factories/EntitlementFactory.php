<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EntitlementSource;
use App\Enums\Format;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    protected $model = Entitlement::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'webinar_id' => Webinar::factory(),
            'format' => Format::Live,
            'source_type' => EntitlementSource::Manual,
            'source_id' => null,
            'granted_at' => now(),
            'expires_at' => null,
            'revoked_at' => null,
            'revoked_by' => null,
            'note' => null,
        ];
    }

    public function format(Format $format): static
    {
        return $this->state(fn (): array => ['format' => $format]);
    }

    /** Spent from a membership, which is the only source that costs an allowance. */
    public function spentFrom(Subscription $subscription): static
    {
        return $this->state(fn (): array => [
            'user_id' => $subscription->user_id,
            'source_type' => EntitlementSource::Subscription,
            'source_id' => $subscription->getKey(),
        ]);
    }

    public function fromOrder(int $orderId): static
    {
        return $this->state(fn (): array => [
            'source_type' => EntitlementSource::Order,
            'source_id' => $orderId,
        ]);
    }

    public function revoked(?string $note = 'Given back'): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
            'note' => $note,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'granted_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function grantedAt(mixed $when): static
    {
        return $this->state(fn (): array => ['granted_at' => $when]);
    }
}
