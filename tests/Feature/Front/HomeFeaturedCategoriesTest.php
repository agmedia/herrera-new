<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class HomeFeaturedCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_home_renders_selected_featured_categories_with_recursive_counts(): void
    {
        $category = $this->createCategory('kucanski-aparati', 'Kućanski aparati', 'kucanski-aparati');
        $subcategory = $this->createCategory('mali-aparati', 'Mali aparati', 'mali-aparati', $category);

        $product = $this->createProduct('mikser', 'Mikser', $category);
        $this->createProduct('toster', 'Toster', $subcategory);
        $this->createProduct('skriveni-artikl', 'Skriveni artikl', $subcategory, false);

        $image = UploadedFile::fake()->image('kucanski-aparati.jpg', 720, 480);
        $category
            ->addMedia($image->getPathname())
            ->usingName('Kućanski aparati')
            ->usingFileName('kucanski-aparati.jpg')
            ->toMediaCollection('category_banner');

        $block = ContentBlock::query()->create([
            'code' => 'home-featured-categories-test',
            'name' => 'Izdvojene kategorije',
            'type' => 'featured_categories',
            'is_active' => true,
        ]);
        $block->translations()->create([
            'locale' => 'hr',
            'title' => 'Izdvojene kategorije',
            'subtitle' => 'Brzi pregled naše ponude',
        ]);
        $block->items()->create([
            'item_type' => 'category',
            'item_id' => $category->id,
            'sort_order' => 0,
        ]);
        $block->slots()->create([
            'placement' => 'home.categories',
            'frontend_variant' => 'desktop',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $productsBlock = ContentBlock::query()->create([
            'code' => 'home-products-after-featured-test',
            'name' => 'Proizvodi nakon kategorija',
            'type' => 'products',
            'is_active' => true,
        ]);
        $productsBlock->translations()->create([
            'locale' => 'hr',
            'title' => 'Proizvodi nakon kategorija',
        ]);
        $productsBlock->items()->create([
            'item_type' => 'product',
            'item_id' => $product->id,
            'sort_order' => 0,
        ]);
        $productsBlock->slots()->create([
            'placement' => 'home.before_products',
            'frontend_variant' => 'desktop',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0 Safari/537.36',
            ])
            ->get('/')
            ->assertOk()
            ->assertSee('data-featured-categories', false)
            ->assertSee('data-featured-category="'.$category->id.'"', false)
            ->assertSee('Izdvojene kategorije')
            ->assertSee('Brzi pregled naše ponude')
            ->assertSee('Kućanski aparati')
            ->assertSee('/category/kucanski-aparati', false)
            ->assertSee('alt="Kućanski aparati – izdvojena kategorija"', false)
            ->assertSee('2 artikla')
            ->assertSee('1 podkategorija')
            ->assertSee('Sve kategorije')
            ->assertSee('href="'.route('categories.index').'"', false)
            ->assertSeeInOrder([
                'class="featured-categories-heading storefront-widget-heading--split"',
                'class="storefront-widget-heading-title"',
                'Sve kategorije',
                'class="featured-categories-grid"',
            ], false)
            ->assertDontSee('featured-categories-cta', false)
            ->assertSeeInOrder(['Izdvojene kategorije', 'Proizvodi nakon kategorija'])
            ->assertDontSee('Skriveni artikl');
    }

    private function createCategory(
        string $code,
        string $name,
        string $slug,
        ?Category $parent = null
    ): Category {
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => $code,
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 0,
            'parent_id' => $parent?->id,
        ]);
        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => $name,
            'slug' => $slug,
        ]);

        return $category;
    }

    private function createProduct(
        string $code,
        string $name,
        Category $category,
        bool $isActive = true
    ): Product {
        $product = Product::query()->create([
            'code' => $code,
            'sku' => strtoupper($code),
            'is_active' => $isActive,
            'base_price' => 99.99,
            'stock_qty' => 10,
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => $name,
            'slug' => $code,
        ]);
        $product->categories()->attach($category->id, [
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        return $product;
    }
}
