<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Industry;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'company' => fake()->company(),
            'job_title' => fake()->randomElement([
                'HR Director', 'Payroll Manager', 'Compliance Officer',
                'People Operations Lead', 'Benefits Administrator',
            ]),
            'phone' => fake()->numerify('+1 ###-###-####'),
            'industry_id' => null,
            'source' => LeadSource::Quiz,
            'source_detail' => null,
            'status' => LeadStatus::New,
            'assigned_to' => null,
            'contacted_at' => null,
            'message' => null,
            'note' => null,
            'marketing_opt_in' => true,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function from(LeadSource $source, ?string $detail = null): static
    {
        return $this->state(fn (): array => [
            'source' => $source,
            'source_detail' => $detail,
        ]);
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (): array => [
            'assigned_to' => $user->getKey(),
            'status' => LeadStatus::Working,
            'contacted_at' => now()->subDay(),
        ]);
    }

    /** Came in and nobody has touched it — what the register exists to surface. */
    public function untouched(int $daysAgo = 5): static
    {
        return $this->state(fn (): array => [
            'status' => LeadStatus::New,
            'assigned_to' => null,
            'contacted_at' => null,
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    /** The same address as an existing account, so the outcome column has something to find. */
    public function matching(User $user): static
    {
        return $this->state(fn (): array => [
            'email' => Str::lower($user->email),
            'name' => $user->name,
        ]);
    }

    public function in(Industry $industry): static
    {
        return $this->state(fn (): array => ['industry_id' => $industry->getKey()]);
    }

    public function fromCampaign(string $source, string $medium, string $campaign): static
    {
        return $this->state(fn (): array => [
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $campaign,
        ]);
    }
}
