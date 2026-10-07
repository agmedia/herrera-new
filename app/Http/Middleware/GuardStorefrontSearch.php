<?php

namespace App\Http\Middleware;

use App\Services\Front\StorefrontSearchPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GuardStorefrontSearch
{
    public function __construct(
        private readonly StorefrontSearchPolicy $policy,
        private readonly ThrottleRequests $throttle,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('search.autocomplete', 'shop.index', 'categories.show', 'manufacturers.show')) {
            return $next($request);
        }

        $queryParameters = $request->query->all();
        try {
            $query = $this->policy->normalize($queryParameters['q'] ?? '');
            if ($query !== '') {
                $this->policy->validatePage($queryParameters['page'] ?? null);
            }
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();
            $response = $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => $exception->errors()], 422)
                : response($message, 422)->header('Content-Type', 'text/plain; charset=UTF-8');
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->setVary('Cookie', false);

            return $response;
        }

        if (array_key_exists('q', $queryParameters)) {
            $request->query->set('q', $query);
        }

        // Ordinary browsing never consumes the search budget; empty autocomplete still does.
        if ($query === '' && ! $request->routeIs('search.autocomplete')) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, 'storefront-search');
    }
}
