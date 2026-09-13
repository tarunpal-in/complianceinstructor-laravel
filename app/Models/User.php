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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Spatie\Permission\Traits\HasRoles;

/**
 * CLAUDE.md §5.8 — users carry NO role column; roles come from Spatie.
 * §14 #12 and §9 both ban raw `role_id === 1` comparisons for authorization.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /**
     * Billable is what gives a user `subscriptions()` and the Stripe customer
     * columns published in `create_customer_columns` — §3 hands Cashier every
     * part of subscription billing, and this is the end it attaches to.
     *
     * @use HasFactory<UserFactory>
     */
    use Billable, HasFactory, HasRoles, Notifiable;

    // Relationships -------------------------------------------------------------

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Everything this person may access — §5.2's ledger from their end.
     *
     * @return HasMany<Entitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    // Who they are ----------------------------------------------------------------

    /**
     * Whether this account is a customer rather than a member of staff.
     *
     * §5.8 gives users no role column, so "customer" is not a value anywhere —
     * it is the absence of the four §8 admin roles. Asked once here rather than
     * written out at each call site, because a screen that answers it slightly
     * differently from the panel gate is how an administrator ends up filed
     * under the people who buy things.
     */
    public function isCustomer(): bool
    {
        return ! $this->hasAnyRole(UserRole::values());
    }

    /** "Customer", or the roles they hold. */
    public function describeRoles(): string
    {
        if ($this->isCustomer()) {
            return 'Customer';
        }

        // getRoleNames() rather than mapping over the relation: Spatie's role
        // model is swappable, so nothing static can say it has a `name`.
        return $this->getRoleNames()
            ->map(fn (string $name): string => UserRole::tryFrom($name)?->label() ?? $name)
            ->join(', ');
    }

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
     *
     * The raw attribute bag is checked first because Model::shouldBeStrict()
     * throws MissingAttributeException for a column the instance never loaded —
     * which is every instance when the migration has not been run yet. Reading
     * a preference is not worth a 500 on every admin page, so an absent column
     * yields the default. Writing one still fails loudly, as it should.
     */
    public function preference(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists('preferences', $this->getAttributes())) {
            return $default;
        }

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
