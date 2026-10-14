<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a reverse proxy (staging/production), trust it so HTTPS,
        // secure cookies, and client IPs resolve correctly. Operator-owned
        // via TRUSTED_PROXIES (config/app.php `trusted_proxies`); empty
        // means trust no proxy. Read here (not via config()) because the
        // container is not ready inside this bootstrap callback.
        $raw = (string) ($_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? '');
        $proxies = array_values(array_filter(array_map(
            trim(...),
            explode(',', $raw)
        )));

        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
