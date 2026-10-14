<?php

namespace Tests\Feature\Front;

use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontThemeFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_preference_loads_before_styles_and_switches_precede_guest_account_links(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $html = $this->get('/')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame('light', $xpath->query('//html')->item(0)->getAttribute('data-theme'));
        $this->assertSame(1, $xpath->query('//head/script[@data-storefront-theme-boot]')->count());
        $this->assertSame(1, $xpath->query('//head/script[@defer and contains(@src, "storefront-theme.js")]')->count());
        $this->assertLessThan(strpos($html, 'rel="stylesheet"'), strpos($html, 'data-storefront-theme-boot'));
        $this->assertStringContainsString('storefront-theme.css', $xpath->query('//head/link[@rel="stylesheet"][last()]')->item(0)->getAttribute('href'));
        $switches = $xpath->query('//*[@data-storefront-theme-toggle]');
        $this->assertSame(2, $switches->count());
        foreach ($switches as $switch) {
            $this->assertSame('switch', $switch->getAttribute('role'));
            $this->assertSame('false', $switch->getAttribute('aria-checked'));
            $this->assertSame('Tamni prikaz', $switch->getAttribute('aria-label'));
            $this->assertSame(route('front.auth.login'), $xpath->query('following-sibling::*[1]', $switch)->item(0)->getAttribute('href'));
            $this->assertSame(2, $xpath->query('.//svg[@viewbox and path]', $switch)->count());
            $this->assertSame(0, $xpath->query('.//svg/use', $switch)->count());
        }
    }

    public function test_theme_switch_is_shared_by_catalog_forms_cart_and_signed_in_account(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        foreach (['/shop', '/contact', '/cart', '/wishlist', '/auth/login', '/auth/register'] as $path) {
            $this->get($path)->assertOk()->assertSee('data-storefront-theme-toggle', false)
                ->assertSee('storefront-theme.css', false);
        }

        $html = $this->actingAs(User::factory()->create())->get(route('account.dashboard'))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        foreach ($xpath->query('//*[@data-storefront-theme-toggle]') as $switch) {
            $this->assertSame(route('account.dashboard'), $xpath->query('following-sibling::*[1]', $switch)->item(0)->getAttribute('href'));
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
