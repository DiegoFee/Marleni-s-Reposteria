<?php

use App\Http\Middleware\EnforceWebSecurity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustHosts(
            at: fn (): array => [
                '^'.preg_quote((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'), '/').'$',
            ],
            subdomains: false,
        );
        $middleware->append(EnforceWebSecurity::class);
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (Response $response): Response {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

            if (app()->environment('production') && request()->isSecure()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
            }

            return $response;
        });
        //
    })->create();
