<?php

namespace App\Http\Middleware;

use App\Support\SearchEngineIndexing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventPreviewIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (SearchEngineIndexing::shouldBlock($request)
            && stripos((string) $response->headers->get('X-Robots-Tag'), 'noindex') === false) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
