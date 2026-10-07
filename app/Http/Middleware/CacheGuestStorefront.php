<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Front\Concerns\ResolvesGridColumns;
use App\Services\Front\GuestStorefrontCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CacheGuestStorefront
{
    use ResolvesGridColumns;

    public function __construct(private readonly GuestStorefrontCache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->eligibleRequest($request) || ($query = $this->safeQuery($request)) === null) {
            return $this->mark($next($request), 'BYPASS');
        }

        try {
            $gridCookie = $request->cookie('front_grid_cols');
            $gridCookie = is_scalar($gridCookie) && in_array((string) $gridCookie, ['1', '2', '3', '4', '5'], true)
                ? (string) $gridCookie : 'default';
            $key = $this->cache->key($request, $query, $gridCookie);
            $entry = $this->cache->get($key);
            if ($entry !== null) {
                if ($request->routeIs('shop.index', 'categories.show', 'manufacturers.show')) {
                    // Match the controller's preference side effect without reusing another visitor's cookies.
                    $this->queueGridColsCookie($this->resolveGridCols($request, $this->defaultDesktopGridCols($request)));
                }

                return $this->mark(response(str_replace($entry['csrf_marker'], e($request->session()->token()), $entry['html']))
                    ->header('Content-Type', 'text/html; charset=UTF-8')
                    ->header('Cache-Control', 'private, no-store, max-age=0'), 'HIT');
            }
        } catch (Throwable) {
            return $this->mark($next($request), 'BYPASS');
        }

        $sessionBefore = $request->session()->all();
        $response = $next($request);
        if (! $this->eligibleResponse($request, $response, $sessionBefore)) {
            return $this->mark($response, 'BYPASS');
        }

        try {
            // Do not publish an old render after a concurrent catalog/settings invalidation.
            if ($this->cache->key($request, $query, $gridCookie) !== $key) {
                return $this->mark($response, 'BYPASS');
            }
            $this->cache->put($key, (string) $response->getContent(), $request->session()->token());
        } catch (Throwable) {
            return $this->mark($response, 'BYPASS');
        }

        return $this->mark($response, 'MISS');
    }

    private function eligibleRequest(Request $request): bool
    {
        if (! $this->cache->enabled() || ! $request->isMethod('GET')
            || ! $request->routeIs(...(array) config('storefront_cache.routes', []))
            || $request->ajax() || $request->expectsJson() || $request->headers->has('Authorization')
            || $request->headers->has('If-None-Match') || $request->headers->has('If-Modified-Since')
            || $request->headers->has('Range') || ! $request->hasSession()
            || $request->user() !== null || ! $this->safeSession($request)
            || ! is_string($request->session()->token()) || strlen($request->session()->token()) < 32
            || Cookie::getQueuedCookies() !== []) {
            return false;
        }

        foreach (array_keys($request->cookies->all()) as $name) {
            // Consent is interpreted by browser JavaScript and never changes server-rendered HTML.
            if (! in_array($name, [(string) config('session.cookie'), 'XSRF-TOKEN', 'front_grid_cols', 'cc_cookie'], true)) {
                return false;
            }
        }

        return true;
    }

    private function safeSession(Request $request): bool
    {
        foreach ($request->session()->all() as $key => $value) {
            if (! in_array($key, ['_token', '_previous', '_flash', 'front_locale'], true)) {
                return false;
            }
            if ($key === '_flash' && (! is_array($value) || array_filter($value) !== [])) {
                return false;
            }
            if ($key === 'front_locale' && ! is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string>|null */
    private function safeQuery(Request $request): ?array
    {
        $parameters = $request->query->all();
        if (count($parameters) > (int) config('storefront_cache.max_query_parameters', 16)
            || strlen(http_build_query($parameters)) > (int) config('storefront_cache.max_query_bytes', 1200)) {
            return null;
        }

        $result = [];
        foreach ($parameters as $key => $value) {
            if (! is_scalar($value) || strlen((string) $value) > 400 || preg_match('/[\x00-\x1f\x7f]/', (string) $value)) {
                return null;
            }
            $value = (string) $value;
            if (in_array($key, ['page', 'per_page', 'cols'], true)) {
                $maximum = match ($key) {
                    'page' => (int) config('storefront_cache.max_page', 200),
                    'per_page' => 100,
                    'cols' => 5,
                };
                if (! ctype_digit($value) || (int) $value < 1 || (int) $value > $maximum) {
                    return null;
                }
            } elseif ($key === 'sort') {
                if (! in_array($value, ['default', 'relevance', 'newest', 'name_asc', 'name_desc', 'price_low', 'price_high', 'stock_high'], true)) {
                    return null;
                }
            } elseif (! in_array($key, ['q', 'category', 'manufacturer'], true)
                && ! preg_match('/^(?:opt|attr)_[1-9][0-9]{0,7}$/D', (string) $key)) {
                return null;
            }
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $sessionBefore */
    private function eligibleResponse(Request $request, Response $response, array $sessionBefore): bool
    {
        if ($response->getStatusCode() !== 200
            || ! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')
            || ! is_string($response->getContent())
            || strlen($response->getContent()) > (int) config('storefront_cache.max_html_bytes', 3 * 1024 * 1024)
            || $response->headers->getCookies() !== [] || $response->headers->has('Set-Cookie')
            || $request->user() !== null || ! $this->safeSession($request)
            || $request->session()->all() !== $sessionBefore) {
            return false;
        }

        foreach (Cookie::getQueuedCookies() as $cookie) {
            if ($cookie->getName() !== 'front_grid_cols' || ! in_array($cookie->getValue(), ['1', '2', '3', '4', '5'], true)) {
                return false;
            }
        }

        return true;
    }

    private function mark(Response $response, string $status): Response
    {
        $response->headers->set('X-Storefront-Cache', $status);

        return $response;
    }
}
