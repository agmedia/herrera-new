<?php

namespace Tests\Feature\Front;

use App\Models\User;
use App\Services\Front\NavigationMenuService;
use App\Services\Front\StorefrontStylesService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontStylesCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_content_version_is_browser_cached_and_identical_for_guests_and_customers(): void
    {
        $styles = app(StorefrontStylesService::class);
        $url = route('front.storefront.styles', ['v' => $styles->version()]);
        $guest = $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');

        $this->actingAs(User::factory()->create())->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private')
            ->assertContent($guest->getContent());
    }

    public function test_unversioned_incorrect_and_malformed_versions_require_revalidation(): void
    {
        foreach ([[], ['v' => 'old-version'], ['v' => ['invalid']]] as $parameters) {
            $this->get(route('front.storefront.styles', $parameters))->assertOk()
                ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private');
        }
    }

    public function test_a_global_style_change_creates_a_new_version_and_does_not_immutably_cache_the_old_url(): void
    {
        $styles = app(StorefrontStylesService::class);
        $oldVersion = $styles->version();
        app(SystemSettingsService::class)->put(NavigationMenuService::APPEARANCE_SETTINGS_KEY, [
            'background_color' => '#aabbcc',
        ]);
        $newVersion = $styles->version();

        $this->assertNotSame($oldVersion, $newVersion);
        $this->get(route('front.storefront.styles', ['v' => $oldVersion]))->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private')
            ->assertSee('--navigation-background-color:#aabbcc', false);
        $this->get(route('front.storefront.styles', ['v' => $newVersion]))->assertOk()
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private')
            ->assertSee('--navigation-background-color:#aabbcc', false);
    }

    public function test_storefront_links_to_the_current_css_content_version(): void
    {
        $version = app(StorefrontStylesService::class)->version();
        $this->get(route('home'))->assertOk()
            ->assertSee(route('front.storefront.styles', ['v' => $version]), false);
    }
}
