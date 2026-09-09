<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Payments\Drivers\ManualDriver;
use App\Payments\Drivers\PayPalDriver;
use App\Payments\Drivers\StripeDriver;
use App\Payments\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * CLAUDE.md §8 improvement #1 — one contract, three drivers, registered
         * in one place. A singleton because the manager holds only the driver
         * map, and because a test swapping a driver in has to be swapping the
         * same instance the application resolves.
         */
        $this->app->singleton(PaymentGatewayManager::class, static function (): PaymentGatewayManager {
            $manager = new PaymentGatewayManager;

            $manager->register(new StripeDriver);
            $manager->register(new PayPalDriver);
            $manager->register(new ManualDriver);

            return $manager;
        });
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
