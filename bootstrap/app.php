<?php

declare(strict_types=1);

use App\Http\Middleware\HandleRedirects;
use App\Http\Middleware\NormalizeUrl;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Both run before anything renders. Redirects first, so a moved URL
        // goes straight to its destination rather than bouncing through its own
        // trailing-slash form on the way (brief §4); then NormalizeUrl gives
        // every surviving public URL its one canonical trailing-slash form
        // (brief §7). Both exclude admin, Livewire, the API and assets.
        $middleware->web(prepend: [HandleRedirects::class, NormalizeUrl::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
