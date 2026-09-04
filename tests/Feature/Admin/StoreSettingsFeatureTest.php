<?php

namespace Tests\Feature\Admin;

use App\Jobs\GenerateWebpConversionsJob;
use App\Livewire\Admin\Settings\System\StoreSettings;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Models\Settings\Local\Language;
use App\Models\User;
use App\Services\Front\StoreSettingsService as FrontStoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class StoreSettingsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_store_settings_page(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/settings/system/store-settings')
            ->assertOk()
            ->assertSee(__('Store Settings'))
            ->assertSee(route('admin.settings.system.store-settings'));
    }

    public function test_editor_cannot_open_store_settings_page(): void
    {
        $editor = $this->makeUserWithRole('editor');

        $this->actingAs($editor)
            ->get('/admin/settings/system/store-settings')
            ->assertForbidden();
    }

    public function test_products_tab_can_save_even_when_newsletter_tab_is_invalid(): void
    {
        $admin = $this->makeUserWithRole('admin');

        app(SystemSettingsService::class)->putMany([
            'store_newsletter_provider' => 'mailchimp',
            'store_newsletter_mailchimp_api_key' => '',
            'store_newsletter_mailchimp_list_id' => '',
            'store_product_fit_finder_enabled' => false,
            'store_search_autocomplete_enabled' => false,
            'store_product_desktop_default_cols' => 4,
            'store_product_mobile_default_cols' => 1,
            'store_product_catalog_pagination_mode' => 'pagination',
            'store_product_filter_option_ids' => [],
            'store_product_filter_attribute_group_codes' => [],
        ]);

        Livewire::actingAs($admin)
            ->test(StoreSettings::class)
            ->set('tab', 'products')
            ->set('form.store_product_fit_finder_enabled', true)
            ->set('form.store_search_autocomplete_enabled', true)
            ->set('form.store_search_autocomplete_products_enabled', true)
            ->set('form.store_search_autocomplete_categories_enabled', true)
            ->set('form.store_search_autocomplete_manufacturers_enabled', true)
            ->set('form.store_search_autocomplete_blog_enabled', true)
            ->set('form.store_search_autocomplete_products_limit', 7)
            ->set('form.store_search_autocomplete_categories_limit', 5)
            ->set('form.store_search_autocomplete_manufacturers_limit', 4)
            ->set('form.store_search_autocomplete_blog_limit', 2)
            ->set('form.store_search_autocomplete_show_product_image', false)
            ->set('form.store_search_autocomplete_show_product_brand', true)
            ->set('form.store_search_autocomplete_show_product_sku', true)
            ->set('form.store_search_autocomplete_show_product_price', false)
            ->set('form.store_product_desktop_default_cols', 5)
            ->set('form.store_product_mobile_default_cols', 2)
            ->set('form.store_product_catalog_pagination_mode', 'load_more')
            ->set('form.store_product_filter_panel_settings.category.visible', true)
            ->set('form.store_product_filter_panel_settings.category.default_open', false)
            ->set('form.store_product_filter_panel_settings.category.max_height', 220)
            ->set('form.store_product_filter_panel_settings.manufacturer.visible', false)
            ->set('form.store_product_filter_panel_settings.price.default_open', true)
            ->set('form.store_product_filter_panel_settings.price.max_height', 360)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $settings = app(SystemSettingsService::class);

        $this->assertTrue((bool) $settings->get('store_product_fit_finder_enabled'));
        $this->assertTrue((bool) $settings->get('store_search_autocomplete_enabled'));
        $this->assertTrue((bool) $settings->get('store_search_autocomplete_categories_enabled'));
        $this->assertTrue((bool) $settings->get('store_search_autocomplete_manufacturers_enabled'));
        $this->assertTrue((bool) $settings->get('store_search_autocomplete_blog_enabled'));
        $this->assertSame(7, (int) $settings->get('store_search_autocomplete_products_limit'));
        $this->assertSame(5, (int) $settings->get('store_search_autocomplete_categories_limit'));
        $this->assertSame(4, (int) $settings->get('store_search_autocomplete_manufacturers_limit'));
        $this->assertSame(2, (int) $settings->get('store_search_autocomplete_blog_limit'));
        $this->assertFalse((bool) $settings->get('store_search_autocomplete_show_product_image'));
        $this->assertTrue((bool) $settings->get('store_search_autocomplete_show_product_brand'));
        $this->assertTrue((bool) $settings->get('store_search_autocomplete_show_product_sku'));
        $this->assertFalse((bool) $settings->get('store_search_autocomplete_show_product_price'));
        $this->assertSame(5, (int) $settings->get('store_product_desktop_default_cols'));
        $this->assertSame(2, (int) $settings->get('store_product_mobile_default_cols'));
        $this->assertSame('load_more', $settings->get('store_product_catalog_pagination_mode'));
        $filterPanelSettings = $settings->get('store_product_filter_panel_settings', []);
        $this->assertTrue((bool) data_get($filterPanelSettings, 'category.visible'));
        $this->assertFalse((bool) data_get($filterPanelSettings, 'category.default_open'));
        $this->assertSame(220, (int) data_get($filterPanelSettings, 'category.max_height'));
        $this->assertFalse((bool) data_get($filterPanelSettings, 'manufacturer.visible'));
        $this->assertSame(360, (int) data_get($filterPanelSettings, 'price.max_height'));
        $this->assertSame('mailchimp', $settings->get('store_newsletter_provider'));
        $this->assertSame('', $settings->get('store_newsletter_mailchimp_api_key'));
        $this->assertSame('', $settings->get('store_newsletter_mailchimp_list_id'));
    }

    public function test_newsletter_tab_still_requires_mailchimp_credentials_when_active(): void
    {
        $admin = $this->makeUserWithRole('superadmin');

        Livewire::actingAs($admin)
            ->test(StoreSettings::class)
            ->set('tab', 'newsletter')
            ->set('form.store_newsletter_provider', 'mailchimp')
            ->set('form.store_newsletter_mailchimp_api_key', '')
            ->set('form.store_newsletter_mailchimp_list_id', '')
            ->call('save')
            ->assertHasErrors([
                'form.store_newsletter_mailchimp_api_key',
                'form.store_newsletter_mailchimp_list_id',
            ]);
    }

    public function test_announcement_tab_saves_scroll_and_color_settings(): void
    {
        $admin = $this->makeUserWithRole('admin');

        Livewire::actingAs($admin)
            ->test(StoreSettings::class)
            ->set('tab', 'announcement')
            ->set('form.store_announcement_enabled', true)
            ->set('form.store_announcement_text', 'Promo')
            ->set('form.store_announcement_url', 'https://example.com/promo')
            ->set('form.store_announcement_new_tab', true)
            ->set('form.store_announcement_scroll_enabled', true)
            ->set('form.store_announcement_scroll_duration_seconds', 24)
            ->set('form.store_announcement_background_color', '#0ea5e9')
            ->set('form.store_announcement_text_color', '#ffffff')
            ->set('form.store_benefits_bar_enabled', true)
            ->set('form.store_benefits_bar_item_1', 'Više od **60 000 proizvoda** u ponudi')
            ->set('form.store_benefits_bar_item_2', 'Plaćanje do **24 rate**')
            ->set('form.store_benefits_bar_item_3', '**Dostava** idući radni dan')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $settings = app(SystemSettingsService::class);

        $this->assertTrue((bool) $settings->get('store_announcement_scroll_enabled'));
        $this->assertSame(24, (int) $settings->get('store_announcement_scroll_duration_seconds'));
        $this->assertSame('#0ea5e9', $settings->get('store_announcement_background_color'));
        $this->assertSame('#ffffff', $settings->get('store_announcement_text_color'));
        $this->assertTrue((bool) $settings->get('store_benefits_bar_enabled'));
        $this->assertSame('Više od **60 000 proizvoda** u ponudi', $settings->get('store_benefits_bar_item_1'));
        $this->assertSame('Plaćanje do **24 rate**', $settings->get('store_benefits_bar_item_2'));
        $this->assertSame('**Dostava** idući radni dan', $settings->get('store_benefits_bar_item_3'));
    }

    public function test_footer_text_and_custom_links_are_saved_per_locale(): void
    {
        $admin = $this->makeUserWithRole('admin');
        config(['app.locale' => 'hr']);

        Language::query()->create([
            'code' => 'hr',
            'locale' => 'hr_HR',
            'name' => 'Croatian',
            'native_name' => 'Hrvatski',
            'direction' => 'ltr',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        Language::query()->create([
            'code' => 'en',
            'locale' => 'en_US',
            'name' => 'English',
            'native_name' => 'English',
            'direction' => 'ltr',
            'is_default' => false,
            'is_active' => false,
            'sort_order' => 2,
        ]);

        app(SystemSettingsService::class)->putMany([
            'store_footer_contact_title' => 'Kontakt i podrška',
            'store_footer_contact_intro' => 'Webshop upiti i informacije',
            'store_footer_col_2_title' => 'Pomoć',
            'store_footer_col_2_custom_links' => 'Kontakt|/contact',
        ]);

        Livewire::actingAs($admin)
            ->test(StoreSettings::class)
            ->set('tab', 'branding')
            ->set('locale', 'en')
            ->set('form.store_footer_contact_title', 'Contact and support')
            ->set('form.store_footer_contact_intro', 'Webshop inquiries and information')
            ->set('form.store_footer_col_2_title', 'Help')
            ->set('form.store_footer_col_2_custom_links', "Contact|/contact\nReturns and claims form|/returns-and-claims")
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(SystemSettingsService::class);
        $this->assertSame('Pomoć', $settings->get('store_footer_col_2_title'));
        $this->assertSame('Help', $settings->get('store_footer_col_2_title_translations')['en']);

        App::setLocale('en');
        $footer = app(FrontStoreSettingsService::class)->footer();
        $this->assertSame('Contact and support', $footer['contact_title']);
        $this->assertSame('Webshop inquiries and information', $footer['contact_intro']);
        $this->assertSame('Help', $footer['link_columns'][1]['title']);
        $this->assertSame('Contact', $footer['link_columns'][1]['links'][0]['label']);
        $this->assertSame('/returns-and-claims', $footer['link_columns'][1]['links'][1]['url']);
    }

    public function test_webp_generation_targets_media_from_all_active_storefront_models(): void
    {
        Queue::fake();

        $admin = $this->makeUserWithRole('superadmin');
        $activeProduct = $this->makeProduct($admin, 'WEBP-ACTIVE', true);
        $inactiveProduct = $this->makeProduct($admin, 'WEBP-INACTIVE', false);
        $activeMedia = $this->makeProductMedia($activeProduct);
        $inactiveMedia = $this->makeProductMedia($inactiveProduct);
        $activeBlock = ContentBlock::query()->create([
            'code' => 'WEBP-HERO-ACTIVE',
            'name' => 'Active hero',
            'type' => 'full_width_image_slider',
            'is_active' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $inactiveBlock = ContentBlock::query()->create([
            'code' => 'WEBP-HERO-INACTIVE',
            'name' => 'Inactive hero',
            'type' => 'full_width_image_slider',
            'is_active' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $activeBlockMedia = $this->makeContentBlockMedia($activeBlock);
        $inactiveBlockMedia = $this->makeContentBlockMedia($inactiveBlock);

        Cache::forget('settings.store.webp_generation.active_products.'.$admin->id);
        Cache::forget('settings.store.webp_coverage.active_products');

        Livewire::actingAs($admin)
            ->test(StoreSettings::class)
            ->set('tab', 'images')
            ->call('startWebpGeneration')
            ->assertSet('webpGeneration.total', 2)
            ->assertSet('webpGeneration.processed', 0);

        $state = Cache::get('settings.store.webp_generation.active_products.'.$admin->id);
        $pendingIds = array_values(array_map('intval', (array) ($state['pending_ids'] ?? [])));

        $this->assertSame([(int) $activeMedia->id, (int) $activeBlockMedia->id], $pendingIds);
        $this->assertNotContains((int) $inactiveMedia->id, $pendingIds);
        $this->assertNotContains((int) $inactiveBlockMedia->id, $pendingIds);
        Queue::assertPushed(GenerateWebpConversionsJob::class);
    }

    public function test_webp_generation_also_optimizes_the_existing_store_logo(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is required.');
        }

        Queue::fake();
        Storage::fake('public');

        $admin = $this->makeUserWithRole('superadmin');
        $logoPath = UploadedFile::fake()
            ->image('store-logo.png', 800, 400)
            ->storeAs('store-settings', 'store-logo.png', 'public');

        app(SystemSettingsService::class)->putMany([
            'store_brand_logo_path' => $logoPath,
            'store_brand_logo_optimized_path' => '',
            'store_brand_logo_width' => 0,
            'store_brand_logo_height' => 0,
        ]);

        Livewire::actingAs($admin)
            ->test(StoreSettings::class)
            ->set('tab', 'images')
            ->call('startWebpGeneration');

        $settings = app(SystemSettingsService::class);
        $optimizedPath = (string) $settings->get('store_brand_logo_optimized_path', '');

        Storage::disk('public')->assertExists($optimizedPath);
        $this->assertSame(320, (int) $settings->get('store_brand_logo_width'));
        $this->assertSame(160, (int) $settings->get('store_brand_logo_height'));
        $this->assertSame([320, 160], array_slice(getimagesize(Storage::disk('public')->path($optimizedPath)), 0, 2));
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::role()->firstOrCreate(['name' => 'editor']);
        Bouncer::role()->firstOrCreate(['name' => 'customer']);

        Bouncer::assign($role)->to($user);

        return $user;
    }

    private function makeProduct(User $user, string $code, bool $isActive): Product
    {
        return Product::query()->create([
            'code' => $code,
            'sku' => $code.'-SKU',
            'is_active' => $isActive,
            'manufacturer_id' => null,
            'tax_rate_id' => null,
            'base_price' => 10,
            'stock_qty' => 5,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    private function makeProductMedia(Product $product): Media
    {
        return Media::query()->create([
            'model_type' => Product::class,
            'model_id' => $product->id,
            'collection_name' => 'product_main',
            'name' => $product->code,
            'file_name' => strtolower($product->code).'.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => 100,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);
    }

    private function makeContentBlockMedia(ContentBlock $block): Media
    {
        return Media::query()->create([
            'model_type' => ContentBlock::class,
            'model_id' => $block->id,
            'collection_name' => 'block_slides',
            'name' => $block->code,
            'file_name' => strtolower($block->code).'.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => 100,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);
    }
}
