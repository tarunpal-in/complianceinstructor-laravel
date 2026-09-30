<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use SensitiveParameter;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * CLAUDE.md §5.8 — users carry NO role column; roles come from Spatie.
 * §14 #12 and §9 both ban raw `role_id === 1` comparisons for authorization.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /**
     * Billable is what gives a user `subscriptions()` and the Stripe customer
     * columns published in `create_customer_columns` — §3 hands Cashier every
     * part of subscription billing, and this is the end it attaches to.
     *
     * @use HasFactory<UserFactory>
     */
    use Billable, HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * §8 — "every mutation written to `activity_log` with the actor".
     *
     * Added when the staff screen was built, because until then nothing
     * recorded who gave somebody access. "Who let Sarah refund payments" is a
     * question with real consequences and the row alone only knows the answer
     * it arrived at.
     *
     * Deliberately narrow. The password hash and the two-factor secret are
     * never logged — an audit trail holding credentials is a second place to
     * steal them from — and neither is anything a customer changes about their
     * own profile, which would bury the staff changes in noise.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'deactivated_at', 'deactivated_by', 'deactivation_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('staff');
    }

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
        // Deactivated staff keep their roles, so "holds a role" is no longer
        // the same question as "may get in". A leaver's history still points
        // at a real person with a job title; their access stops here.
        return ! $this->isDeactivated() && $this->hasAnyRole(UserRole::values());
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
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    // Staff lifecycle — §8 improvement #3 ------------------------------------------

    /**
     * Whether this account is a member of staff rather than a customer.
     *
     * Holding a role, deactivated or not. A leaver keeps theirs so the admin
     * can still say what they had, and so reinstating is one click rather than
     * a guess.
     */
    public function isStaff(): bool
    {
        return $this->roles->isNotEmpty();
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deactivated_by');
    }

    /** How this account stands, for a badge. */
    public function describeAccess(): string
    {
        return match (true) {
            ! $this->isStaff() => 'No admin access',
            $this->isDeactivated() => 'Deactivated',
            default => 'Active',
        };
    }

    public function accessColor(): string
    {
        return match (true) {
            ! $this->isStaff() => 'gray',
            $this->isDeactivated() => 'danger',
            default => 'success',
        };
    }

    // Two-factor authentication ------------------------------------------------------

    /**
     * The shared secret an authenticator app was set up with.
     *
     * Encrypted at rest. Filament hashes recovery codes itself but hands the
     * secret over raw — reasonably, since it has to be readable to compute the
     * expected code — so encrypting it is this model's job. A secret in plain
     * text is a database dump away from every administrator's second factor.
     *
     * Backed onto the column Fortify's migration already added, so the staff
     * screen's 2FA badge and its Reset action keep reading the same place.
     */
    public function getAppAuthenticationSecret(): ?string
    {
        $secret = $this->two_factor_secret;

        if (blank($secret)) {
            return null;
        }

        try {
            return decrypt($secret);
        } catch (DecryptException) {
            // A secret written under a different APP_KEY cannot be recovered.
            // Returning null makes it read as "not set up", which is the only
            // honest answer and leaves the account reachable by password.
            return null;
        }
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret === null ? null : encrypt($secret),
            /*
             * Filament has no notion of this column; Fortify's migration added
             * it and the staff screen reads it. Stamped here so "2FA: On"
             * means a secret actually exists, and cleared with it so removing
             * two-factor does not leave the screen claiming otherwise.
             */
            'two_factor_confirmed_at' => $secret === null ? null : now(),
        ])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        // What the authenticator app lists the account under. The email rather
        // than the name, because somebody with two accounts needs to tell them
        // apart in a list of six-digit codes.
        return $this->email;
    }

    /**
     * @return array<string>|null
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        $codes = $this->two_factor_recovery_codes;

        if (blank($codes)) {
            return null;
        }

        try {
            $decoded = json_decode(decrypt($codes), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string>|null  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        // Already hashed by Filament before they reach here — these are
        // verified, never displayed again. Encrypted as well because the
        // column holds them as one blob and the secret beside it is encrypted.
        $this->forceFill([
            'two_factor_recovery_codes' => $codes === null ? null : encrypt(json_encode($codes)),
        ])->save();
    }

    /**
     * Whether two-factor authentication is actually in force on this account.
     *
     * Confirmed, not merely started. Fortify writes the secret when somebody
     * begins setting it up and stamps `two_factor_confirmed_at` only once they
     * have entered a working code — so the secret alone means an abandoned
     * attempt, which is no protection at all.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}
