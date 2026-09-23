<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\Drivers\ManualDriver;
use App\Payments\Drivers\PayPalDriver;
use App\Payments\Drivers\StripeDriver;
use App\Payments\PaymentGatewayManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

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
        // §5.5 — Cashier keeps ownership of the subscriptions table, and our
        // model adds the plan link and the allowance snapshot on top. Wiring it
        // here rather than only using it in our own queries matters: Cashier
        // builds subscription records itself during checkout and webhook
        // handling, and those instances have to be this class too.
        Cashier::useSubscriptionModel(Subscription::class);

        // Super Admin passes every gate. Without this, each new permission added
        // in P2+ would have to be reseeded onto the role before it took effect.
        Gate::before(static function (User $user, string $ability): ?bool {
            return $user->hasRole(UserRole::SuperAdmin->value) ? true : null;
        });

        /*
         * §9 — "rate limiting on login, registration, reset, coupon application
         * and checkout". A public comment box belongs on that list for the same
         * reason: it is an unauthenticated endpoint that writes a row.
         *
         * By IP rather than by email, because the email is supplied by whoever
         * is posting and a script would simply vary it. Five a minute is far
         * more than a person reading an article will ever need, and far less
         * than a script wants.
         */
        RateLimiter::for('comments', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->ip()));

        // CLAUDE.md §3 — money is integer cents and nothing silently coerces.
        // Unguarded attributes would reintroduce §14 #1 (mass assignment of a
        // price from a request), and lazy loading is what made the predecessor's
        // dashboards slow enough to need caching.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
