<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * CLAUDE.md §5.8 — users carry NO role column; roles come from Spatie.
 * §14 #12 and §9 both ban raw `role_id === 1` comparisons for authorization.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Gate on the admin panel.
     *
     * Without this method Filament lets EVERY authenticated user into /admin —
     * which would mean any customer who registered on the storefront could open
     * the admin panel. Access is granted only to the four §8 admin roles;
     * customers hold none of them.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(UserRole::values());
    }

    /**
     * Read one key out of the per-user preference bag.
     *
     * Never mass-assignable: `preferences` is deliberately absent from
     * #[Fillable], so a request cannot write it.
     */
    public function preference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }

    public function setPreference(string $key, mixed $value): void
    {
        $preferences = $this->preferences ?? [];
        data_set($preferences, $key, $value);

        $this->forceFill(['preferences' => $preferences])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }
}
