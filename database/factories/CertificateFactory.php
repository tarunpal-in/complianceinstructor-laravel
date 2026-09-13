<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Accreditation;
use App\Models\Certificate;
use App\Models\User;
use App\Models\Webinar;
use App\Support\CertificateSerial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        $learner = User::factory();
        $webinar = Webinar::factory();
        $accreditation = Accreditation::factory();

        return [
            'user_id' => $learner,
            'webinar_id' => $webinar,
            'accreditation_id' => $accreditation,

            'serial' => CertificateSerial::generate(),

            // The snapshot the document is made of. Resolved in afterMaking so
            // it matches the records actually created rather than repeating
            // faker values that belong to nothing.
            'learner_name' => fake()->name(),
            'webinar_title' => fake()->sentence(5),
            'accreditation_code' => fake()->randomElement(['HRCI', 'SHRM', 'CPE', 'APA']),
            'credits' => fake()->randomElement(['1.00', '1.50', '2.00', '3.00']),

            'issued_at' => now(),
            'issued_by' => null,
            'revoked_at' => null,
            'revoked_by' => null,
            'revoked_reason' => null,
            'pdf_path' => null,
        ];
    }

    /** A certificate whose snapshot matches the records it points at. */
    public function forLearner(User $learner, Webinar $webinar, Accreditation $accreditation, string $credits = '2.00'): static
    {
        return $this->state(fn (): array => [
            'user_id' => $learner->getKey(),
            'webinar_id' => $webinar->getKey(),
            'accreditation_id' => $accreditation->getKey(),
            'learner_name' => $learner->name,
            'webinar_title' => $webinar->title,
            'accreditation_code' => $accreditation->code,
            'credits' => $credits,
        ]);
    }

    public function revoked(string $reason = 'Issued in error'): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }

    public function issuedAt(mixed $when): static
    {
        return $this->state(fn (): array => ['issued_at' => $when]);
    }

    public function withPdf(): static
    {
        return $this->state(fn (): array => ['pdf_path' => 'certificates/'.fake()->uuid().'.pdf']);
    }
}
