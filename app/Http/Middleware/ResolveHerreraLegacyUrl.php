<?php

namespace App\Http\Middleware;

use App\Services\Import\HerreraLegacyUrlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveHerreraLegacyUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $result = app(HerreraLegacyUrlService::class)->resolve($request);
        if ($result) {
            if ($result['destination']) {
                return redirect($result['destination'], 301);
            }

            return response('<!doctype html><html lang="hr"><meta charset="utf-8"><title>Sadržaj više nije dostupan | Herrera</title><main><h1>Sadržaj više nije dostupan</h1><p>Ova stara adresa označena je za provjeru ili pripada sadržaju koji više nije dostupan.</p><a href="/shop">Pregledajte Herrera katalog</a></main></html>', 410)->header('X-Robots-Tag', 'noindex');
        }

        return $next($request);
    }
}
