<?php

namespace Tests\Feature\Front;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class GuestStorefrontCacheIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'commerce.b2b_only' => true,
            'storefront_cache.enabled' => true,
            'storefront_cache.store' => 'array',
            'cache.default' => 'array',
        ]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        Cache::store('array')->flush();
    }

    public function test_real_home_route_hits_cache_and_keeps_private_browser_headers(): void
    {
        $this->get(route('home'))->assertOk()->assertHeader('X-Storefront-Cache', 'MISS');
        $hit = $this->get(route('home'))->assertOk()->assertHeader('X-Storefront-Cache', 'HIT');
        $this->assertStringContainsString('no-store', $hit->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $hit->headers->get('Cache-Control'));
        $this->assertNotEmpty($hit->headers->getCookies());
    }

    public function test_real_catalogue_route_hits_cache_and_preserves_grid_preference(): void
    {
        $this->get(route('shop.index'))->assertOk()->assertHeader('X-Storefront-Cache', 'MISS');
        // PHP-FPM starts a fresh cookie queue per HTTP request; the test application is reused.
        foreach (Cookie::getQueuedCookies() as $cookie) {
            Cookie::unqueue($cookie->getName(), $cookie->getPath());
        }
        $hit = $this->get(route('shop.index'))->assertOk()->assertHeader('X-Storefront-Cache', 'HIT');
        $hit->assertCookie('front_grid_cols');
    }
}
