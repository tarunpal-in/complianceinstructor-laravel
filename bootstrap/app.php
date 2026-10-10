<?php

declare(strict_types=1);

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
        // One URL form for every public page — bare paths 301 to their
        // trailing-slash canonical (brief §7). Prepended so the redirect
        // happens before anything renders. It excludes admin, Livewire, the
        // API and assets itself.
        $middleware->web(prepend: [NormalizeUrl::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
