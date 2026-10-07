<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Page\InfoPage;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Front\NavigationMenuService;
use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontPublicDataCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_settings_reuse_resolved_links_and_refresh_after_a_settings_save(): void
    {
        config(['app.locale' => 'hr']);
        app()->setLocale('hr');
        $page = InfoPage::query()->create(['code' => 'memoized-page', 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'title' => 'Informacije', 'slug' => 'informacije']);
        $settings = app(SystemSettingsService::class);
        $settings->putMany(['store_brand_name' => 'Herrera', 'store_footer_col_1_page_ids' => [$page->id]]);
        $store = app(StoreSettingsService::class);

        $first = $store->all();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $second = $store->all();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertSame([], $queries);
        $settings->put('store_brand_name', 'Herrera promjena');
        $this->assertSame('Herrera promjena', $store->all()['branding']['store_name']);
    }

    public function test_resolved_settings_are_separate_for_each_locale(): void
    {
        config(['app.locale' => 'hr']);
        app(SystemSettingsService::class)->putMany([
            'store_footer_col_1_title' => 'Proizvodi',
            'store_footer_col_1_title_translations' => ['hr' => 'Proizvodi', 'en' => 'Products'],
        ]);
        $store = app(StoreSettingsService::class);

        app()->setLocale('hr');
        $this->assertSame('Proizvodi', $store->all()['footer']['link_columns'][0]['title']);
        app()->setLocale('en');
        $this->assertSame('Products', $store->all()['footer']['link_columns'][0]['title']);
    }

    public function test_public_navigation_reuses_its_tree_and_refreshes_with_the_catalog_revision(): void
    {
        config(['storefront_cache.enabled' => true, 'storefront_cache.store' => 'array', 'app.locale' => 'hr']);
        Cache::store('array')->flush();
        $category = Category::query()->create(['scope' => Category::SCOPE_CATALOG, 'code' => 'cached-root', 'is_active' => true, 'show_in_menu' => true]);
        $category->translations()->create(['scope' => Category::SCOPE_CATALOG, 'locale' => 'hr', 'name' => 'Kategorija', 'slug' => 'kategorija']);
        $settings = app(SystemSettingsService::class);
        $settings->put(NavigationMenuService::SETTINGS_KEY, [['type' => 'catalog', 'label' => 'Proizvodi', 'is_active' => true, 'show_dropdown' => true]]);
        $first = (new NavigationMenuService($settings))->forLocale('hr');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $second = (new NavigationMenuService($settings))->forLocale('hr');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertSame([], $queries);
        $category->translations()->where('locale', 'hr')->update(['name' => 'Promijenjena kategorija']);
        app(GuestStorefrontCache::class)->invalidate();
        $updated = (new NavigationMenuService($settings))->forLocale('hr');
        $this->assertSame('Promijenjena kategorija', $updated[0]['children'][0]['label']);
    }
}
