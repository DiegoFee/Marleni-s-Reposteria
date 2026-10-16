<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceWebSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        // -----------------------------------------------------------
        // Logica critica: Oculta las rutas internas de Boost para impedir su acceso desde la aplicacion.
        // -----------------------------------------------------------
        if ($request->is('_boost', '_boost/*')) {
            abort(404);
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
