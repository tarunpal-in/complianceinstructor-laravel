<?php

declare(strict_types=1);

namespace App\Providers;

use App\Routing\TrailingSlashUrlGenerator;
use Illuminate\Support\ServiceProvider;

/**
 * Swaps Laravel's URL generator for one that emits trailing-slash URLs for
 * public content (see TrailingSlashUrlGenerator).
 *
 * This re-registers the `url` singleton the same way the framework's
 * RoutingServiceProvider does, with our subclass in place of the stock
 * generator. Redefining the singleton discards the framework's `extend()`
 * wrapper — which is what sets the session and key resolvers that signed URLs
 * depend on — so those are re-applied here. The one thing changed is the class;
 * everything else mirrors the framework, and is kept close to it so an upgrade
 * that alters the registration is easy to follow.
 */
class UrlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();

            // The generator needs the route collection from the router, passed
            // by reference so new routes are visible to it.
            $app->instance('routes', $routes);

            return new TrailingSlashUrlGenerator(
                $routes,
                $app->rebinding('request', function ($app, $request): void {
                    $app['url']->setRequest($request);
                }),
                $app['config']['app.asset_url'],
            );
        });

        $this->app->extend('url', function (TrailingSlashUrlGenerator $url, $app): TrailingSlashUrlGenerator {
            // Signed URLs need both of these; without them hasValidSignature has
            // no key and the "previous URL" helpers have no session.
            $url->setSessionResolver(fn () => $app['session'] ?? null);

            $url->setKeyResolver(fn (): array => [
                $app['config']->get('app.key'),
                ...($app['config']->get('app.previous_keys') ?? []),
            ]);

            // Keep the generator's routes current if the collection is rebound,
            // for example when routes are cached.
            $app->rebinding('routes', function ($app, $routes): void {
                $app['url']->setRoutes($routes);
            });

            return $url;
        });
    }
}
