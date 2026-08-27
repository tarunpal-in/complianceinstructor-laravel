<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super Admin passes every gate. Without this, each new permission added
        // in P2+ would have to be reseeded onto the role before it took effect.
        Gate::before(static function (User $user, string $ability): ?bool {
            return $user->hasRole(UserRole::SuperAdmin->value) ? true : null;
        });

        // CLAUDE.md §3 — money is integer cents and nothing silently coerces.
        // Unguarded attributes would reintroduce §14 #1 (mass assignment of a
        // price from a request), and lazy loading is what made the predecessor's
        // dashboards slow enough to need caching.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
