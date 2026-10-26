<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\PreserveZeroFraction;
use App\Http\Middleware\TouchLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Token-authenticated broadcast auth for the mobile/web apps: POST /api/broadcasting/auth
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Nginx Proxy Manager: trust X-Forwarded-* so URLs and client IPs are correct.
        $middleware->trustProxies(at: '*');
        // API has no login page: unauthenticated requests get 401 JSON, never a redirect.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*', 'broadcasting/*') ? null : ($request->is('app/*') ? '/app/signed-out' : '/'));
        $middleware->api(append: [PreserveZeroFraction::class]);
        // WebRTC call setup (SDP) must keep its trailing CRLF: trimmed, the other phone can't read it
        // ("SessionDescription is NULL") and the call never connects.
        $middleware->trimStrings(except: ['payload.*']);
        $middleware->alias([
            'role' => EnsureRole::class,
            'last-seen' => TouchLastSeen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('broadcasting/*') || $request->expectsJson(),
        );
    })->create();
