<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Hydrated explicitly so Model::shouldBeStrict() does not throw when
            // a factory-built instance is read for a preference.
            'preferences' => null,
            /*
             * Same reason, and it matters more: canAccessPanel() reads this on
             * every admin request, and create() does not refresh from the
             * database — so a factory-made instance would be missing the one
             * column that decides whether somebody may get in.
             *
             * Deliberately not handled by making the accessor tolerant. An
             * absent column there would have to be read as "not deactivated",
             * which fails open on an access control. Better that a genuinely
             * unrun migration is loud.
             */
            'deactivated_at' => null,
            'deactivated_by' => null,
            'deactivation_reason' => null,
            /*
             * Same again. Filament's multi-factor provider reads the secret to
             * decide whether the profile page shows "Set up" or "Disable", so
             * a factory-made account without these columns takes the whole
             * profile page down.
             *
             * Also left strict rather than made tolerant: a reader that
             * shrugged at a missing secret would report two-factor as off for
             * somebody who has it on.
             */
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
