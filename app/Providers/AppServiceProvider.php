<?php

declare(strict_types=1);

namespace App\Providers;

use App\Email\Dispatcher;
use App\Email\SendBudget;
use App\Enums\UserRole;
use App\Mail\PasswordChanged;
use App\Mail\ResetPassword;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\Drivers\ManualDriver;
use App\Payments\Drivers\PayPalDriver;
use App\Payments\Drivers\StripeDriver;
use App\Payments\PaymentGatewayManager;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
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

        /*
         * The send rate, read by the SendEmail job's RateLimited middleware.
         *
         * Not a nicety. A consumer Gmail account throttles at roughly 20–30
         * messages a minute and answers a burst with a temporary block on the
         * whole account — which takes password resets down along with the
         * reminder blast that caused it. A job over the limit is released back
         * to the queue, so nothing is lost; it simply goes out slower.
         */
        RateLimiter::for('email', static fn (): Limit => Limit::perMinute(SendBudget::perMinuteCap()));

        /*
         * Tell somebody their password changed.
         *
         * On the model rather than on an event, because there are two ways it
         * happens — the profile screen and a reset link — and Filament fires an
         * event for only one of them. A dirty `password` column is the one fact
         * both paths share.
         *
         * `updated` and not `saved`, which is what excludes an invitation: it
         * creates the account with a random throwaway password, and "your
         * password was changed" is a confusing first thing to hear from a system
         * you have not joined yet. Testing `wasRecentlyCreated` instead looks
         * equivalent and is not — it stays true for the whole life of the
         * instance, so it would also swallow a genuine change made moments after
         * the account was created.
         */
        User::updated(static function (User $user): void {
            if (! $user->wasChanged('password') || blank($user->email)) {
                return;
            }

            app(Dispatcher::class)->send(new PasswordChanged($user));
        });

        /*
         * Catch the one reset path that does not come through the Dispatcher.
         *
         * Filament's password-reset page passes its own callback to Laravel's
         * broker, which means `User::sendPasswordResetNotification` — our hook —
         * is never called, and the reset email would be the only one in the
         * system with no row in the log. "I never got the link" is the single
         * most common thing support is asked, so that is the worst possible
         * email to be missing.
         *
         * Returning false cancels Filament's notification; ours goes instead,
         * carrying the URL Filament had already computed so the link is identical.
         */
        Event::listen(static function (NotificationSending $event): ?bool {
            if (! $event->notification instanceof FilamentResetPassword) {
                return null;
            }

            if (! $event->notifiable instanceof User) {
                return null;
            }

            app(Dispatcher::class)->send(
                new ResetPassword($event->notifiable, $event->notification->url),
            );

            return false;
        });

        // CLAUDE.md §3 — money is integer cents and nothing silently coerces.
        // Unguarded attributes would reintroduce §14 #1 (mass assignment of a
        // price from a request), and lazy loading is what made the predecessor's
        // dashboards slow enough to need caching.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
