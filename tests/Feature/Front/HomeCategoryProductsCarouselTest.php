<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class HomeCategoryProductsCarouselTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_category_carousel_loads_products_and_uses_category_card_style(): void
    {
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'small-appliances',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 1,
        ]);
        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => 'Mali kućanski aparati',
            'slug' => 'mali-kucanski-aparati',
            'description' => null,
        ]);

        $product = Product::query()->create([
            'code' => 'test-appliance',
            'sku' => 'TEST-APPLIANCE',
            'is_active' => true,
            'base_price' => 129.99,
            'stock_qty' => 10,
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => 'Testni kućanski aparat',
            'slug' => 'testni-kucanski-aparat',
            'excerpt' => null,
            'description' => null,
        ]);
        $product->categories()->attach($category->id, [
            'sort_order' => 1,
            'is_primary' => true,
        ]);
        $image = UploadedFile::fake()->image('appliance.jpg', 900, 1200);
        $product
            ->addMedia($image->getPathname())
            ->usingName('Testni kućanski aparat')
            ->usingFileName('appliance.jpg')
            ->toMediaCollection('product_main');

        foreach ([2, 3] as $index) {
            $additionalProduct = Product::query()->create([
                'code' => "test-appliance-{$index}",
                'sku' => "TEST-APPLIANCE-{$index}",
                'is_active' => true,
                'base_price' => 129.99 + $index,
                'stock_qty' => 10,
            ]);
            $additionalProduct->translations()->create([
                'locale' => 'hr',
                'name' => "Testni kućanski aparat {$index}",
                'slug' => "testni-kucanski-aparat-{$index}",
                'excerpt' => null,
                'description' => null,
            ]);
            $additionalProduct->categories()->attach($category->id, [
                'sort_order' => $index,
                'is_primary' => true,
            ]);
            $additionalImage = UploadedFile::fake()->image("appliance-{$index}.jpg", 900, 1200);
            $additionalProduct
                ->addMedia($additionalImage->getPathname())
                ->usingName("Testni kućanski aparat {$index}")
                ->usingFileName("appliance-{$index}.jpg")
                ->toMediaCollection('product_main');
        }

        $block = ContentBlock::query()->create([
            'code' => 'home-category-products-test',
            'name' => 'Home Category Products Test',
            'type' => 'category_products_carousel',
            'is_active' => true,
            'payload' => null,
        ]);
        $block->translations()->create([
            'locale' => 'hr',
            'title' => 'Preporučujemo',
            'subtitle' => 'Odabrani uređaji za svaki dom',
            'body_html' => null,
            'cta_label' => null,
            'cta_url' => null,
            'payload' => ['items_limit' => 12],
        ]);
        $background = UploadedFile::fake()->image('carousel-background.jpg', 1440, 480);
        $block
            ->addMedia($background->getPathname())
            ->usingName('Carousel background')
            ->usingFileName('carousel-background.jpg')
            ->toMediaCollection('block_background');
        $block->items()->create([
            'item_type' => 'category',
            'item_id' => $category->id,
            'sort_order' => 0,
        ]);
        $block->slots()->create([
            'placement' => 'home.before_products',
            'frontend_variant' => 'desktop',
            'target_type' => null,
            'target_ref' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0 Safari/537.36',
            ])
            ->get('/')
            ->assertOk()
            ->assertSee('Preporučujemo')
            ->assertSee('Odabrani uređaji za svaki dom')
            ->assertSee('iz kategorije')
            ->assertSee('Mali kućanski aparati')
            ->assertSee('background-image:url(&#039;', false)
            ->assertSee('carousel-background.jpg', false)
            ->assertSee('/category/mali-kucanski-aparati', false)
            ->assertSee('Testni kućanski aparat')
            ->assertSee('data-product-card-lined', false)
            ->assertSee('border-top: 1px solid #e2e8f0;', false)
            ->assertSee('border-bottom: 1px solid #e2e8f0;', false)
            ->assertSee('<section class="w-full bg-white', false)
            ->assertSee('class="storefront-widget-heading--split"', false)
            ->assertSee('class="storefront-widget-heading-title"', false)
            ->assertSee('class="storefront-widget-heading-meta"', false)
            ->assertSee('class="mt-4 storefront-widget-wide"', false)
            ->assertDontSee('style="width: min(calc(100vw - 2rem), var(--storefront-container-width, 1860px));"', false)
            ->assertSee('data-products-carousel-splide', false)
            ->assertSee('data-products-carousel-swipe-hint', false)
            ->assertSee('arrows: false', false)
            ->assertSee('pagination: count > mobilePerPage', false);
    }

    public function test_home_category_carousel_filters_brands_limits_products_and_sorts_by_price_and_date(): void
    {
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'sorted-products-category',
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 1,
        ]);
        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => 'Sortirani artikli',
            'slug' => 'sortirani-artikli',
        ]);

        $includedBrand = $this->createManufacturer('included-brand', 'Uključeni brend');
        $excludedBrand = $this->createManufacturer('excluded-brand', 'Isključeni brend');

        $this->createCarouselProduct(
            $category,
            $includedBrand,
            'old-low-product',
            'Stari jeftini artikl',
            10,
            '2026-01-01 10:00:00',
            1
        );
        $this->createCarouselProduct(
            $category,
            $includedBrand,
            'middle-price-product',
            'Srednji artikl',
            20,
            '2026-02-01 10:00:00',
            2
        );
        $this->createCarouselProduct(
            $category,
            $includedBrand,
            'new-high-product',
            'Novi skupi artikl',
            30,
            '2026-03-01 10:00:00',
            3
        );
        $this->createCarouselProduct(
            $category,
            $excludedBrand,
            'excluded-product',
            'Artikl drugog brenda',
            40,
            '2026-04-01 10:00:00',
            4
        );

        $block = ContentBlock::query()->create([
            'code' => 'home-sorted-category-products-test',
            'name' => 'Home Sorted Category Products Test',
            'type' => 'category_products_carousel',
            'is_active' => true,
        ]);
        $translation = $block->translations()->create([
            'locale' => 'hr',
            'title' => 'Sortirani proizvodi',
            'payload' => [
                'items_limit' => 2,
                'manufacturer_ids' => [$includedBrand->id],
                'product_sort' => 'price_desc',
            ],
        ]);
        $block->items()->create([
            'item_type' => 'category',
            'item_id' => $category->id,
            'sort_order' => 0,
        ]);
        $block->slots()->create([
            'placement' => 'home.before_products',
            'frontend_variant' => 'desktop',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $priceDescendingResponse = $this->getDesktopHome();
        $priceDescendingResponse
            ->assertOk()
            ->assertSeeInOrder(['Novi skupi artikl', 'Srednji artikl'])
            ->assertDontSee('Stari jeftini artikl')
            ->assertDontSee('Artikl drugog brenda');

        $translation->update([
            'payload' => [
                'items_limit' => 3,
                'manufacturer_ids' => [$includedBrand->id],
                'product_sort' => 'price_asc',
            ],
        ]);
        ContentBlockResolver::bumpCacheVersion();
        $this->getDesktopHome()
            ->assertOk()
            ->assertSeeInOrder(['Stari jeftini artikl', 'Srednji artikl', 'Novi skupi artikl']);

        $translation->update([
            'payload' => [
                'items_limit' => 3,
                'manufacturer_ids' => [$includedBrand->id],
                'product_sort' => 'date_desc',
            ],
        ]);
        ContentBlockResolver::bumpCacheVersion();
        $this->getDesktopHome()
            ->assertOk()
            ->assertSeeInOrder(['Novi skupi artikl', 'Srednji artikl', 'Stari jeftini artikl']);

        $translation->update([
            'payload' => [
                'items_limit' => 3,
                'manufacturer_ids' => [$includedBrand->id],
                'product_sort' => 'date_asc',
            ],
        ]);
        ContentBlockResolver::bumpCacheVersion();
        $this->getDesktopHome()
            ->assertOk()
            ->assertSeeInOrder(['Stari jeftini artikl', 'Srednji artikl', 'Novi skupi artikl']);
    }

    private function createManufacturer(string $code, string $name): Manufacturer
    {
        $manufacturer = Manufacturer::query()->create([
            'code' => $code,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $manufacturer->translations()->create([
            'locale' => 'hr',
            'name' => $name,
            'slug' => $code,
        ]);

        return $manufacturer;
    }

    private function createCarouselProduct(
        Category $category,
        Manufacturer $manufacturer,
        string $code,
        string $name,
        float $price,
        string $createdAt,
        int $sortOrder
    ): Product {
        $product = Product::query()->create([
            'code' => $code,
            'sku' => strtoupper($code),
            'is_active' => true,
            'manufacturer_id' => $manufacturer->id,
            'base_price' => $price,
            'stock_qty' => 10,
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => $name,
            'slug' => $code,
        ]);
        $product->categories()->attach($category->id, [
            'sort_order' => $sortOrder,
            'is_primary' => true,
        ]);
        $image = UploadedFile::fake()->image("{$code}.jpg", 900, 1200);
        $product
            ->addMedia($image->getPathname())
            ->usingName($name)
            ->usingFileName("{$code}.jpg")
            ->toMediaCollection('product_main');
        $product->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $product;
    }

    private function getDesktopHome()
    {
        return $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0 Safari/537.36',
            ])
            ->get('/');
    }
}
