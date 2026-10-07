<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\LegacyCatalogImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyCatalogImageFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.herrera.hr']);
        Storage::fake('legacy-images-test');
        config(['legacy_media.local_root' => Storage::disk('legacy-images-test')->path('')]);
        Http::preventStrayRequests();
    }

    public function test_it_encodes_original_paths_without_fetching_or_using_the_cache(): void
    {
        $this->assertSame('https://www.herrera.hr/image/catalog/Proizvodi/%C5%BDuti%20proizvod.JPG', LegacyCatalogImage::url('catalog/Proizvodi/Žuti proizvod.JPG'));
        $this->assertSame('https://www.herrera.hr/image/catalog/products/00023.jpg', LegacyCatalogImage::url('/image/catalog/products/00023.jpg'));
        $this->assertSame('https://www.herrera.hr/image/catalog/products/00023.jpg', LegacyCatalogImage::url('https://www.herrera.hr/image/catalog/products/00023.jpg'));
        Http::assertNothingSent();
    }

    #[DataProvider('unsafePaths')]
    public function test_it_rejects_unsafe_or_non_catalog_sources(mixed $path): void
    {
        $this->assertNull(LegacyCatalogImage::url($path));
    }

    public static function unsafePaths(): array
    {
        return array_map(static fn ($path): array => [$path], [
            null, '', ['catalog/a.jpg'], 'image/cache/catalog/a.jpg', 'cache/a.jpg',
            'catalog/../private.jpg', 'catalog/%2e%2e/private.jpg', 'catalog/%252e%252e/private.jpg',
            'catalog/a.jpg?token=secret', 'catalog/a.jpg#fragment', 'catalog\\a.jpg',
            "catalog/a\0.jpg", 'catalog/a.php', 'catalog/a.jpg//evil.jpg',
            'https://evil.test/image/catalog/a.jpg', 'http://www.herrera.hr/image/catalog/a.jpg',
            'https://user@www.herrera.hr/image/catalog/a.jpg', 'https://www.herrera.hr:444/image/catalog/a.jpg',
            'https://www.herrera.hr/image/catalog/a.jpg?token=secret', '//evil.test/catalog/a.jpg',
        ]);
    }

    public function test_remote_fallback_is_optional_and_local_originals_always_win(): void
    {
        config(['legacy_media.enabled' => false]);
        $this->assertNull(LegacyCatalogImage::url('catalog/products/item.jpg'));
        Storage::disk('legacy-images-test')->put('catalog/products/item.jpg', 'local-original');
        $this->assertSame(asset('image/catalog/products/item.jpg'), LegacyCatalogImage::url('catalog/products/item.jpg'));
        config(['legacy_media.enabled' => true]);
        $this->assertSame(asset('image/catalog/products/item.jpg'), LegacyCatalogImage::url('catalog/products/item.jpg'));
    }

    public function test_empty_local_files_do_not_override_the_live_original(): void
    {
        Storage::disk('legacy-images-test')->put('catalog/products/empty.jpg', '');
        $this->assertSame('https://www.herrera.hr/image/catalog/products/empty.jpg', LegacyCatalogImage::url('catalog/products/empty.jpg'));
    }

    public function test_gallery_accepts_the_actual_importer_image_metadata_and_sort_order(): void
    {
        $product = $this->product();
        $payload = $product->payload;
        $payload['opencart']['gallery_images'] = [
            ['image' => 'catalog/products/00026.jpg', 'sort_order' => 3],
            ['image' => 'catalog/products/00025.jpg', 'sort_order' => 1],
            ['image' => '../unsafe.jpg', 'sort_order' => 0],
        ];
        $product->payload = $payload;
        $this->assertSame([
            'https://www.herrera.hr/image/catalog/products/00023.jpg',
            'https://www.herrera.hr/image/catalog/products/00025.jpg',
            'https://www.herrera.hr/image/catalog/products/00026.jpg',
        ], LegacyCatalogImage::gallery($product)->all());
        Http::assertNothingSent();
    }

    public function test_usable_uploaded_media_wins_and_demo_records_do_not_get_legacy_images(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $media = $product->addMedia(UploadedFile::fake()->image('uploaded.jpg', 20, 20))->toMediaCollection('product_main');
        $product->unsetRelation('media');
        $this->assertSame($media->getUrl(), LegacyCatalogImage::first($product));
        $demo = Product::query()->create(['code' => 'demo-without-legacy-image', 'is_active' => true, 'base_price' => 1]);
        $this->assertNull(LegacyCatalogImage::first($demo));
        $this->assertCount(0, LegacyCatalogImage::gallery($demo));
    }

    public function test_guest_product_images_gallery_search_and_schema_keep_b2b_prices_private(): void
    {
        config(['commerce.b2b_only' => true]);
        $product = $this->product();
        app(SystemSettingsService::class)->put('store_search_autocomplete_enabled', true);
        $main = 'https://www.herrera.hr/image/catalog/products/00023.jpg';
        $second = 'https://www.herrera.hr/image/catalog/products/00024.jpg';
        $this->get(route('products.show', ['slug' => 'legacy-image-product']))
            ->assertOk()->assertSee($main, false)->assertSee($second, false)
            ->assertDontSee('9999.11', false)->assertDontSee('data-product-detail-form', false)
            ->assertDontSee('"offers":', false)->assertSee('data-b2b-price-access', false);
        $this->get(route('shop.index'))->assertOk()->assertSee($main, false)->assertDontSee('9999.11', false);
        $this->getJson(route('search.autocomplete', ['q' => 'Legacy image']))
            ->assertOk()->assertJsonPath('groups.products.items.0.image_url', $main);
        Http::assertNothingSent();
    }

    public function test_herrera_home_uses_brand_and_real_catalog_without_public_prices(): void
    {
        config(['commerce.b2b_only' => true]);
        $settings = app(SystemSettingsService::class);
        $settings->put('store_brand_name', 'Herrera');
        $settings->put('store_brand_logo_path', 'assets/brand/herrera-logo.svg');
        $settings->put('store_brand_logo_optimized_path', '');
        $this->product();
        $category = Category::query()->create(['scope' => Category::SCOPE_CATALOG, 'code' => 'legacy-category', 'is_active' => true, 'payload' => ['opencart' => ['image' => 'catalog/categories/category.jpg']]]);
        $category->translations()->create(['locale' => 'hr', 'name' => 'Herrera kategorija', 'slug' => 'herrera-kategorija']);
        $this->get(route('home'))->assertOk()
            ->assertSee('herrera-storefront', false)->assertSee('herrera-home-title', false)
            ->assertSee(asset('assets/brand/herrera-logo.svg'), false)
            ->assertSee('Herrera kategorija')->assertSee('Legacy image product')
            ->assertSee('https://www.herrera.hr/image/catalog/categories/category.jpg', false)
            ->assertDontSee('9999.11', false)->assertDontSee('data-product-card-form', false);
    }

    public function test_herrera_preview_can_disable_inherited_newsletter_claims(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera', 'store_newsletter_enabled' => false,
            'store_newsletter_title' => 'Unverified first purchase discount',
        ]);
        $this->get(route('home'))->assertOk()->assertDontSee('<section class="site-footer-newsletter">', false)
            ->assertDontSee('Unverified first purchase discount');
    }

    private function product(): Product
    {
        $product = Product::query()->create([
            'code' => 'legacy-image', 'sku' => 'legacy-image', 'is_active' => true, 'base_price' => 9999.11, 'stock_qty' => 7,
            'payload' => ['opencart' => ['image' => 'catalog/products/00023.jpg', 'gallery_images' => ['catalog/products/00024.jpg', 'catalog/products/00023.jpg', '../unsafe.jpg']]],
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Legacy image product', 'slug' => 'legacy-image-product']);

        return $product;
    }
}
