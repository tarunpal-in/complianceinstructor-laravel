<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Inquiry>
 */
class InquiryFactory extends Factory
{
    protected $model = Inquiry::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Unique by default: repeat enquiries from one address are a
            // behaviour the register has to show, so two unrelated seeded
            // people must not accidentally look like the same person.
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'company' => fake()->company(),
            'phone' => fake()->numerify('+1 ###-###-####'),
            // The contact form is the intake this register was built around.
            'source' => InquirySource::Contact,
            'subject' => fake()->sentence(5),
            'message' => fake()->paragraph(),
            'status' => InquiryStatus::New,
            'assigned_to' => null,
            'contacted_at' => null,
            'note' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function from(InquirySource $source, ?string $subject = null): static
    {
        return $this->state(fn (): array => array_filter([
            'source' => $source,
            'subject' => $subject,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function about(string $subject): static
    {
        return $this->state(fn (): array => ['subject' => $subject]);
    }

    public function status(InquiryStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (): array => [
            'assigned_to' => $user->getKey(),
            'status' => InquiryStatus::Working,
            'contacted_at' => now()->subDay(),
        ]);
    }

    /** Came in and nobody has touched it — what the register exists to surface. */
    public function untouched(int $daysAgo = 5): static
    {
        return $this->state(fn (): array => [
            'status' => InquiryStatus::New,
            'assigned_to' => null,
            'contacted_at' => null,
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    /** The same address as an existing account, so the Customer column has something to find. */
    public function matching(User $user): static
    {
        return $this->state(fn (): array => [
            'email' => Str::lower($user->email),
            'name' => $user->name,
        ]);
    }

    public function fromAddress(string $email): static
    {
        return $this->state(fn (): array => ['email' => Str::lower($email)]);
    }
}
