<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Modules\Finance\FinanceServiceProvider;
use Modules\Foundation\Authentication\Http\Middleware\EnsureActivePropertyContext;
use Modules\Foundation\Authentication\Http\Middleware\EnsureAuthenticatedSecurity;
use Modules\Foundation\Authorization\Http\Middleware\SetPermissionTeamIdMiddleware;
use Modules\Foundation\FoundationServiceProvider;
use Modules\Operations\OperationsServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        FoundationServiceProvider::class,
        OperationsServiceProvider::class,
        FinanceServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Must run after StartSession so Auth::user() resolves from the session.
            // Sets Spatie Permission team context (property_id) for every web request.
            SetPermissionTeamIdMiddleware::class,
            AuthenticateSession::class,
            EnsureAuthenticatedSecurity::class,
        ]);

        $middleware->api(prepend: ['throttle:api']);

        $middleware->validateCsrfTokens(except: [
            'owner-activation/*',
            'auth/mfa/*',
        ]);

        // Named alias for API routes: apply AFTER auth:sanctum so the token-resolved
        // user is available. Usage: Route::middleware(['auth:sanctum', 'permission.team'])
        $middleware->alias([
            'permission.team' => SetPermissionTeamIdMiddleware::class,
            'active.property' => EnsureActivePropertyContext::class,
            'auth.security' => EnsureAuthenticatedSecurity::class,
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
