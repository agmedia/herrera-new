<?php

namespace Tests\Feature\Front;

use App\Http\Middleware\CacheGuestStorefront;
use App\Models\User;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class GuestStorefrontCacheTest extends TestCase
{
    private GuestStorefrontCache $cache;

    private CacheGuestStorefront $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'commerce.b2b_only' => true,
            'storefront_cache.enabled' => true,
            'storefront_cache.store' => 'array',
            'storefront_cache.ttl_seconds' => 120,
            'cache.default' => 'array',
        ]);
        Cache::store('array')->flush();
        $settings = Mockery::mock(SystemSettingsService::class);
        $settings->shouldReceive('getInt')->with('store_product_desktop_default_cols', 4, 4, 5)->andReturn(4);
        app()->instance(SystemSettingsService::class, $settings);
        $this->cache = new GuestStorefrontCache;
        $this->middleware = new CacheGuestStorefront($this->cache);
    }

    public function test_cached_body_uses_each_visitors_csrf_token_and_never_replays_headers_or_cookies(): void
    {
        $calls = 0;
        $render = function (Request $request) use (&$calls): Response {
            $calls++;

            return response('<!doctype html><form><input name="_token" value="'.$request->session()->token().'"></form>')
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('X-Private-Request', 'request-one-only');
        };
        $first = $this->request();
        $second = $this->request();
        $firstResponse = $this->middleware->handle($first, $render);
        $secondResponse = $this->middleware->handle($second, $render);

        $this->assertSame('MISS', $firstResponse->headers->get('X-Storefront-Cache'));
        $this->assertSame('HIT', $secondResponse->headers->get('X-Storefront-Cache'));
        $this->assertSame(1, $calls);
        $this->assertNotSame($first->session()->token(), $second->session()->token());
        $this->assertStringContainsString($second->session()->token(), $secondResponse->getContent());
        $this->assertStringNotContainsString($first->session()->token(), $secondResponse->getContent());
        $this->assertNull($secondResponse->headers->get('X-Private-Request'));
        $this->assertSame([], $secondResponse->headers->getCookies());
        $this->assertStringContainsString('private', $secondResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $secondResponse->headers->get('Cache-Control'));
        $entry = $this->cache->get($this->cache->key($first, [], 'default'));
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString($first->session()->token(), $entry['html']);
        $this->assertStringContainsString($entry['csrf_marker'], $entry['html']);
    }

    public function test_browser_consent_cookie_keeps_guest_hits_with_the_current_session_token(): void
    {
        $first = $this->request();
        $render = fn (Request $request) => $this->html('<input name="_token" value="'.$request->session()->token().'">');
        $this->middleware->handle($first, $render);
        $consented = $this->request(cookies: ['cc_cookie' => '{"categories":["necessary"]}']);
        $response = $this->middleware->handle($consented, $render);
        $this->assertSame('HIT', $response->headers->get('X-Storefront-Cache'));
        $this->assertStringContainsString($consented->session()->token(), $response->getContent());
        $this->assertStringNotContainsString($first->session()->token(), $response->getContent());
    }

    public function test_signed_in_customers_cannot_read_or_populate_the_guest_entry(): void
    {
        $guest = $this->request();
        $this->middleware->handle($guest, fn () => $this->html('guest, prices hidden'));
        $customer = $this->request(user: new User(['name' => 'Private customer']));
        $response = $this->middleware->handle($customer, fn () => $this->html('private customer price 123.45'));
        $this->assertSame('BYPASS', $response->headers->get('X-Storefront-Cache'));
        $this->assertStringContainsString('private customer price', $response->getContent());
        $after = $this->middleware->handle($this->request(), fn () => $this->html('unexpected live render'));
        $this->assertSame('HIT', $after->headers->get('X-Storefront-Cache'));
        $this->assertStringNotContainsString('123.45', $after->getContent());
        config(['commerce.b2b_only' => false]);
        $publicPricing = $this->middleware->handle($this->request(), fn () => $this->html('retail prices'));
        $this->assertSame('BYPASS', $publicPricing->headers->get('X-Storefront-Cache'));
    }

    #[DataProvider('personalizedSessions')]
    public function test_personalized_or_flash_sessions_bypass_both_reads_and_writes(array $state): void
    {
        $this->middleware->handle($this->request(), fn () => $this->html('clean guest'));
        $response = $this->middleware->handle($this->request(state: $state), fn () => $this->html('private session content'));
        $this->assertSame('BYPASS', $response->headers->get('X-Storefront-Cache'));
        $this->assertStringContainsString('private session content', $response->getContent());
        $this->cache->invalidate();
        $this->middleware->handle($this->request(state: $state), fn () => $this->html('private session content'));
        $clean = $this->middleware->handle($this->request(), fn () => $this->html('clean guest'));
        $this->assertSame('MISS', $clean->headers->get('X-Storefront-Cache'));
        $this->assertStringNotContainsString('private session content', $clean->getContent());
    }

    public static function personalizedSessions(): array
    {
        return [
            'cart' => [['front' => ['cart' => ['items' => [42 => 2]]]]],
            'coupon' => [['front' => ['cart' => ['coupon_code' => 'PRIVATE']]]],
            'wishlist' => [['front' => ['wishlist' => ['product_ids' => [42]]]]],
            'flash message' => [['success' => 'Personal confirmation']],
            'flash metadata' => [['_flash' => ['new' => ['success'], 'old' => []]]],
            'validation errors' => [['errors' => ['newsletter' => ['email' => 'Private email']]]],
            'old input' => [['_old_input' => ['newsletter_email' => 'private@example.test']]],
            'fit finder' => [['front_fit_finder_profile' => ['size' => 'M']]],
            'recently viewed' => [['front_recently_viewed_products' => [42]]],
            'impersonation' => [['admin' => ['customer_impersonation' => ['customer_id' => 7]]]],
            'unknown state' => [['future_personal_state' => 'private']],
        ];
    }

    public function test_mobile_locale_host_grid_cookie_and_query_each_partition_entries(): void
    {
        $calls = 0;
        $render = function () use (&$calls): Response {
            return $this->html('render '.++$calls);
        };
        foreach ([
            $this->request(),
            $this->request(userAgent: 'iPhone Mobile'),
            $this->request(locale: 'en', state: ['front_locale' => 'en']),
            $this->request(host: 'other.example.test'),
            $this->request(cookies: ['front_grid_cols' => '5']),
            $this->request(query: ['page' => '2']),
            $this->request(query: ['page' => '2', 'sort' => 'newest']),
        ] as $request) {
            $this->assertSame('MISS', $this->middleware->handle($request, $render)->headers->get('X-Storefront-Cache'));
        }
        $sorted = $this->middleware->handle($this->request(query: ['sort' => 'newest', 'page' => '2']), $render);
        $this->assertSame('HIT', $sorted->headers->get('X-Storefront-Cache'));
        $this->assertSame(7, $calls);
    }

    public function test_product_and_sensitive_routes_and_non_html_requests_bypass(): void
    {
        foreach (['products.show', 'front.auth.login', 'cart.index', 'wishlist.index', 'account.dashboard', 'checkout.create', 'contact.create', 'search.autocomplete'] as $route) {
            $this->assertSame('BYPASS', $this->middleware->handle($this->request(route: $route), fn () => $this->html('live'))->headers->get('X-Storefront-Cache'));
        }
        foreach ([
            $this->request(method: 'POST'),
            $this->request(headers: ['Authorization' => 'Bearer private']),
            $this->request(headers: ['Accept' => 'application/json']),
            $this->request(headers: ['X-Requested-With' => 'XMLHttpRequest']),
            $this->request(headers: ['If-None-Match' => 'private-etag']),
            $this->request(cookies: ['front_fit_finder_profile' => 'private']),
            $this->request(query: ['token' => 'private-reset-token']),
            $this->request(query: ['q' => ['array']]),
            $this->request(query: ['page' => '999999']),
            $this->request(query: ['q' => str_repeat('q', 401)]),
        ] as $request) {
            $this->assertSame('BYPASS', $this->middleware->handle($request, fn () => $this->html('live'))->headers->get('X-Storefront-Cache'));
        }
    }

    public function test_redirects_errors_json_cookies_and_session_mutations_are_not_stored(): void
    {
        $renderers = [
            fn () => redirect('/auth/login'),
            fn () => $this->html('not found')->setStatusCode(404),
            fn () => response()->json(['private' => true]),
            fn () => $this->html('private cookie')->withCookie(cookie('secret', 'private')),
            function (Request $request) {
                $request->session()->put('success', 'Personal confirmation');

                return $this->html('Personal confirmation');
            },
            function () {
                Cookie::queue(cookie('personal', 'private'));

                return $this->html('private queued cookie');
            },
        ];
        foreach ($renderers as $render) {
            $this->cache->invalidate();
            $response = $this->middleware->handle($this->request(), $render);
            $this->assertSame('BYPASS', $response->headers->get('X-Storefront-Cache'));
            $this->clearQueuedCookies();
            $clean = $this->middleware->handle($this->request(), fn () => $this->html('clean'));
            $this->assertSame('MISS', $clean->headers->get('X-Storefront-Cache'));
        }
    }

    public function test_list_cache_hits_preserve_only_the_normalized_grid_preference_side_effect(): void
    {
        $render = function () {
            Cookie::queue(cookie('front_grid_cols', '5', 525600));

            return $this->html('five columns');
        };
        $first = $this->middleware->handle($this->request(route: 'shop.index', query: ['cols' => '5']), $render);
        $this->assertSame('MISS', $first->headers->get('X-Storefront-Cache'));
        $second = $this->middleware->handle($this->request(route: 'shop.index', query: ['cols' => '5']), $render);
        $this->assertSame('HIT', $second->headers->get('X-Storefront-Cache'));
        $this->assertSame('5', Cookie::queued('front_grid_cols')->getValue());
        $this->assertSame([], $second->headers->getCookies());
    }

    public function test_bulk_public_writes_invalidate_after_commit_and_rollback_preserves_the_revision(): void
    {
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $connection->statement('CREATE TABLE products (id INTEGER PRIMARY KEY, stock_qty INTEGER)');
        $connection->statement('CREATE TABLE sessions (id INTEGER PRIMARY KEY, payload TEXT)');
        DB::listen(fn (QueryExecuted $query) => $this->cache->onQueryExecuted($query));
        $revision = $this->cache->revision();
        $connection->table('sessions')->insert(['id' => 1, 'payload' => 'ignored']);
        $this->assertSame($revision, $this->cache->revision());
        $connection->table('products')->insert(['id' => 1, 'stock_qty' => 3]);
        $afterInsert = $this->cache->revision();
        $this->assertNotSame($revision, $afterInsert);

        $connection->beginTransaction();
        $connection->table('products')->where('id', 1)->update(['stock_qty' => 2]);
        $this->assertSame($afterInsert, $this->cache->revision());
        $connection->rollBack();
        $this->assertSame($afterInsert, $this->cache->revision());
        $connection->beginTransaction();
        $connection->statement('UPDATE "products" SET stock_qty = 1');
        $this->assertSame($afterInsert, $this->cache->revision());
        $connection->commit();
        $this->assertNotSame($afterInsert, $this->cache->revision());
    }

    public function test_cache_errors_degrade_to_the_live_response_and_ttl_expires(): void
    {
        $this->middleware->handle($this->request(), fn () => $this->html('old'));
        $this->travel(121)->seconds();
        $afterExpiry = $this->middleware->handle($this->request(), fn () => $this->html('new'));
        $this->assertSame('MISS', $afterExpiry->headers->get('X-Storefront-Cache'));
        $this->assertStringContainsString('new', $afterExpiry->getContent());
        $this->travelBack();
        config(['storefront_cache.store' => 'missing-store']);
        $broken = $this->middleware->handle($this->request(), fn () => $this->html('live despite cache failure'));
        $this->assertSame('BYPASS', $broken->headers->get('X-Storefront-Cache'));
        $this->assertStringContainsString('live despite cache failure', $broken->getContent());
        $this->cache->invalidate();
    }

    private function request(
        string $route = 'home',
        array $state = [],
        array $query = [],
        array $cookies = [],
        array $headers = [],
        ?User $user = null,
        string $method = 'GET',
        string $locale = 'hr',
        string $userAgent = 'Desktop browser',
        string $host = 'example.test',
    ): Request {
        $this->clearQueuedCookies();
        $request = Request::create('https://'.$host.'/cache-test', $method, $query, $cookies);
        $request->headers->set('User-Agent', $userAgent);
        $request->headers->add($headers);
        $session = new Store('cache-test', new ArraySessionHandler(120));
        $session->start();
        $session->put($state);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        $routeObject = new Route([$method], '/cache-test', fn () => null);
        $routeObject->name($route);
        $request->setRouteResolver(fn () => $routeObject);
        $request->attributes->set('front_locale', $locale);
        $request->attributes->set('frontend_variant', 'desktop');

        return $request;
    }

    private function html(string $content): Response
    {
        return response('<!doctype html><html>'.$content.'</html>')->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function clearQueuedCookies(): void
    {
        foreach (Cookie::getQueuedCookies() as $cookie) {
            Cookie::unqueue($cookie->getName(), $cookie->getPath());
        }
    }
}
