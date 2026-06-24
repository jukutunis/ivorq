<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        Modules\Foundation\FoundationServiceProvider::class,
        Modules\Operations\OperationsServiceProvider::class,
        Modules\Finance\FinanceServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            // Must run after StartSession so Auth::user() resolves from the session.
            // Sets Spatie Permission team context (property_id) for every web request.
            \Modules\Foundation\Authorization\Http\Middleware\SetPermissionTeamIdMiddleware::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Modules\Foundation\Authentication\Http\Middleware\EnsureUserIsActive::class,
        ]);

        $middleware->api(prepend: [
            'throttle:api',
            \Modules\Foundation\Authentication\Http\Middleware\EnsureUserIsActive::class,
        ]);

        // Named alias for API routes: apply AFTER auth:sanctum so the token-resolved
        // user is available. Usage: Route::middleware(['auth:sanctum', 'permission.team'])
        $middleware->alias([
            'permission.team' => \Modules\Foundation\Authorization\Http\Middleware\SetPermissionTeamIdMiddleware::class,
            'active.property' => \Modules\Foundation\Authentication\Http\Middleware\EnsureActivePropertyContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON errors when: (a) the path is under api/*, OR
        // (b) the client signals it expects JSON via Accept header (wantsJson /
        // expectsJson). The second condition preserves the default Laravel
        // behaviour for token-based auth routes like POST /auth/login that live
        // outside the api/* prefix but are consumed by API clients.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
