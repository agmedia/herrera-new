<?php

namespace Tests\Feature\Front;

use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class HerreraSiteIconsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_herrera_storefront_and_auth_use_the_supplied_icon_set_without_changing_saved_branding(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->putMany([
            'store_brand_name' => 'Herrera',
            'store_brand_logo_path' => 'brand/custom-logo.svg',
            'store_brand_favicon_path' => 'brand/legacy-favicon.png',
            'store_brand_favicon_ico_path' => 'brand/legacy-favicon.ico',
            'store_brand_favicon_180_path' => 'brand/legacy-touch-icon.png',
        ]);
        $branding = app(StoreSettingsService::class)->branding();

        foreach ([route('home'), route('front.auth.login'), route('login')] as $url) {
            $response = $this->get($url)->assertOk();
            foreach (['favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png',
                'android-chrome-192x192.png', 'android-chrome-512x512.png', 'site.webmanifest'] as $filename) {
                $response->assertSee('assets/herrera/icons/'.$filename.'?v=', false);
            }
            $response->assertSee('rel="apple-touch-icon" sizes="180x180"', false)
                ->assertSee('rel="manifest"', false)
                ->assertDontSee('brand/legacy-favicon', false)
                ->assertDontSee('brand/legacy-touch-icon', false)
                ->assertSee($branding['logo_url'], false);
        }

        $this->assertSame('brand/legacy-favicon.ico', $settings->get('store_brand_favicon_ico_path'));
        $this->assertSame('brand/custom-logo.svg', $settings->get('store_brand_logo_path'));
    }

    public function test_other_storefronts_keep_their_configured_icons_and_apple_touch_image(): void
    {
        $branding = ['store_name' => 'Termol', 'favicon_url' => '/custom/legacy.png', 'favicons' => [
            'ico_url' => '/custom/favicon.ico', '16_url' => '/custom/favicon-16.png',
            '32_url' => '/custom/favicon-32.png', '180_url' => '/custom/touch.png',
            '192_url' => '/custom/icon-192.png', '512_url' => '/custom/icon-512.png',
        ]];
        $html = Blade::render('<x-front.site-icons :branding="$branding" />', compact('branding'));

        foreach ($branding['favicons'] as $url) {
            $this->assertStringContainsString('href="'.$url.'"', $html);
        }
        $this->assertStringContainsString('rel="apple-touch-icon" sizes="180x180" href="/custom/touch.png"', $html);
        $this->assertStringNotContainsString('assets/herrera/icons', $html);
        $this->assertStringNotContainsString('rel="manifest"', $html);
        $this->assertStringNotContainsString('/custom/legacy.png', $html);
    }

    public function test_other_storefronts_still_support_a_single_legacy_favicon_url(): void
    {
        $branding = ['store_name' => 'Termol', 'favicon_url' => '/custom/favicon.png'];
        $html = Blade::render('<x-front.site-icons :branding="$branding" />', compact('branding'));

        $this->assertStringContainsString('rel="icon" href="/custom/favicon.png"', $html);
        $this->assertStringNotContainsString('assets/herrera/icons', $html);
    }

    public function test_bundled_mobile_icons_and_manifest_reference_valid_transparent_images(): void
    {
        $base = public_path('assets/herrera/icons');
        foreach ([16 => 'favicon-16x16.png', 32 => 'favicon-32x32.png', 180 => 'apple-touch-icon.png',
            192 => 'android-chrome-192x192.png', 512 => 'android-chrome-512x512.png'] as $size => $filename) {
            $image = getimagesize($base.'/'.$filename);
            $this->assertSame([$size, $size, IMAGETYPE_PNG], array_slice($image, 0, 3));
            // PNG IHDR color type 6 retains the source's RGBA transparency.
            $this->assertSame(6, ord(file_get_contents($base.'/'.$filename)[25]));
        }

        $ico = file_get_contents($base.'/favicon.ico');
        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
        $this->assertSame(['reserved' => 0, 'type' => 1, 'count' => 3], $header);
        foreach ([16, 32, 48] as $index => $size) {
            $this->assertSame($size, ord($ico[6 + $index * 16]));
            $this->assertSame($size, ord($ico[7 + $index * 16]));
        }

        $manifest = json_decode(file_get_contents($base.'/site.webmanifest'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame(['192x192', '512x512'], array_column($manifest['icons'], 'sizes'));
        foreach ($manifest['icons'] as $icon) {
            $path = parse_url($icon['src'], PHP_URL_PATH);
            parse_str(parse_url($icon['src'], PHP_URL_QUERY), $query);
            $this->assertFileExists($base.'/'.$path);
            $this->assertSame(substr(hash_file('sha256', $base.'/'.$path), 0, 12), $query['v']);
            $this->assertSame('any', $icon['purpose']);
        }
    }
}
