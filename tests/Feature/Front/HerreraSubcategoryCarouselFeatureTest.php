<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HerreraSubcategoryCarouselFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
    }

    public function test_carousel_shows_only_visible_immediate_children_in_catalog_order_below_title(): void
    {
        $root = $this->category('carousel-root');
        $last = $this->category('carousel-last', $root, ['sort_order' => 20]);
        $first = $this->category('carousel-first', $root, ['sort_order' => 10]);
        $grandchild = $this->category('carousel-grandchild', $first);
        foreach ([['is_active' => false], ['starts_at' => now()->addHour()], ['ends_at' => now()->subHour()], ['scope' => Category::SCOPE_BLOG]] as $index => $visibility) {
            $this->category('carousel-hidden-'.$index, $root, $visibility);
        }
        $this->category('carousel-unrelated');

        $response = $this->get(route('categories.show', ['slug' => 'carousel-root']))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame([$first->id, $last->id], $this->cardIds($xpath));
        $this->assertSame('Podkategorije (2)', trim($xpath->query('//*[@id="category-subcategories-'.$root->id.'-title"]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//main/*[contains(@class, "herrera-catalog-heading-band")]/following-sibling::*[1][@data-herrera-subcategory-carousel]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-herrera-subcategory-carousel]/following-sibling::*[contains(@class, "catalog-filter-section")]')->count());
        $this->assertSame(2, $xpath->query('//*[@data-subcategory-controls]//button[@aria-controls="category-subcategories-'.$root->id.'"]')->count());
        $this->assertSame(route('categories.show', ['slug' => 'carousel-first']), $xpath->query('//*[@data-subcategory-card="'.$first->id.'"]')->item(0)->getAttribute('href'));
        $this->assertStringContainsString('carousel-first', $xpath->query('//*[@data-subcategory-card="'.$first->id.'"]')->item(0)->textContent);

        $nested = $this->get(route('categories.show', ['slug' => 'carousel-first']))->assertOk();
        $this->assertSame([$grandchild->id], $this->cardIds($this->xpath($nested->getContent())));
    }

    public function test_leaf_categories_shop_and_other_stores_do_not_render_the_carousel_or_its_assets(): void
    {
        $root = $this->category('carousel-leaf-root');
        $this->category('carousel-leaf', $root);
        foreach (['carousel-leaf'] as $slug) {
            $this->get(route('categories.show', ['slug' => $slug]))->assertOk()
                ->assertDontSee('data-herrera-subcategory-carousel', false)
                ->assertDontSee('herrera-subcategory-carousel.css', false)
                ->assertDontSee('herrera-subcategory-carousel.js', false);
        }
        $this->get(route('shop.index'))->assertOk()->assertDontSee('data-herrera-subcategory-carousel', false);
        app(SystemSettingsService::class)->put('store_brand_name', 'Termol');
        $this->get(route('categories.show', ['slug' => 'carousel-leaf-root']))->assertOk()
            ->assertDontSee('data-herrera-subcategory-carousel', false)
            ->assertDontSee('herrera-subcategory-carousel.css', false)
            ->assertDontSee('herrera-subcategory-carousel.js', false)
            ->assertViewHas('subcategories', fn ($children) => $children->count() === 1);
    }

    public function test_navigation_remains_available_when_filters_or_products_are_hidden(): void
    {
        $root = $this->category('carousel-panels');
        $child = $this->category('carousel-panels-child', $root);
        foreach ([Category::PAYLOAD_SHOW_FILTERS, Category::PAYLOAD_SHOW_PRODUCTS] as $setting) {
            $root->update(['payload' => [$setting => false]]);
            $response = $this->get(route('categories.show', ['slug' => 'carousel-panels']))->assertOk()
                ->assertDontSee('class="catalog-filter-section', false)
                ->assertSee('herrera-subcategory-carousel.js', false);
            $this->assertSame([$child->id], $this->cardIds($this->xpath($response->getContent())));
        }
    }

    public function test_croatian_photography_is_used_with_localized_names_and_links_and_precedes_legacy_media(): void
    {
        config(['app.locale' => 'en', 'app.fallback_locale' => 'en',
            'herrera-category-photography.fotografija-dijete' => 'assets/brand/herrera-logo.svg']);
        app()->setLocale('en');
        $root = $this->category('carousel-localized', locale: 'en');
        $child = $this->category('child-photo', $root, ['payload' => ['opencart' => ['image' => 'catalog/old.jpg']]], 'en');
        $child->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => 'Fotografija dijete', 'slug' => 'fotografija-dijete']);

        $response = $this->get(route('categories.show', ['slug' => 'carousel-localized']))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $card = $xpath->query('//*[@data-subcategory-card="'.$child->id.'"]')->item(0);
        $this->assertSame(route('categories.show', ['slug' => 'child-photo']), $card->getAttribute('href'));
        $this->assertStringContainsString('child-photo', $card->textContent);
        $image = $xpath->query('.//img', $card)->item(0);
        $this->assertSame(asset('assets/brand/herrera-logo.svg'), $image->getAttribute('src'));
        $this->assertSame('', $image->getAttribute('alt'));
        $this->assertSame('Subcategories (1)', trim($xpath->query('//*[@id="category-subcategories-'.$root->id.'-title"]')->item(0)->textContent));
        $this->assertTrue($response->viewData('subcategoryCards')->first()->relationLoaded('media'));
    }

    public function test_uploaded_category_media_takes_precedence_over_generated_photography(): void
    {
        Storage::fake('public');
        config(['herrera-category-photography.carousel-upload-child' => 'assets/brand/herrera-logo.svg']);
        $root = $this->category('carousel-upload');
        $child = $this->category('carousel-upload-child', $root);
        $media = $child->addMedia(UploadedFile::fake()->image('chosen-category.jpg', 360, 360))
            ->toMediaCollection('category_banner');

        $response = $this->get(route('categories.show', ['slug' => 'carousel-upload']))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $image = $xpath->query('//*[@data-subcategory-card="'.$child->id.'"]//img')->item(0);
        $this->assertStringContainsString('chosen-category', $image->getAttribute('src'));
        $this->assertStringNotContainsString('herrera-logo', $image->getAttribute('src'));
        $this->assertTrue(is_file($media->getPath()));
    }

    public function test_parent_link_returns_one_level_up_in_desktop_and_mobile_filters_including_leaf_categories(): void
    {
        $root = $this->category('return-root');
        $parent = $this->category('return-parent', $root);
        $this->category('return-leaf', $parent);

        $response = $this->get(route('categories.show', ['slug' => 'return-leaf']))->assertOk();
        $links = $this->xpath($response->getContent())->query('//*[@data-parent-category-link]');
        $this->assertSame(2, $links->count());
        foreach ($links as $link) {
            $this->assertSame(route('categories.show', ['slug' => 'return-parent']), $link->getAttribute('href'));
            $this->assertSame('Natrag na return-parent', trim($link->textContent));
        }

        $this->get(route('categories.show', ['slug' => 'return-root']))->assertOk()
            ->assertDontSee('data-parent-category-link', false);
    }

    public function test_empty_children_are_hidden_but_descendant_products_keep_a_child_visible_with_one_count_batch(): void
    {
        $root = $this->category('empty-navigation-root');
        $empty = $this->category('empty-navigation-child', $root, withProduct: false);
        $inactiveOnly = $this->category('inactive-only-child', $root);
        $inactiveOnly->products()->first()->update(['is_active' => false]);
        $branch = $this->category('nonempty-navigation-branch', $root, withProduct: false);
        $this->category('nonempty-navigation-leaf', $branch);

        foreach ([true, false] as $showFilters) {
            $root->update(['payload' => [Category::PAYLOAD_SHOW_FILTERS => $showFilters]]);
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $response = $this->get(route('categories.show', ['slug' => 'empty-navigation-root']))->assertOk();
                $countQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'recursive_filter_categories'));
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame([$branch->id], $this->cardIds($this->xpath($response->getContent())));
            $this->assertSame('Podkategorije (1)', trim($this->xpath($response->getContent())->query('//*[@id="category-subcategories-'.$root->id.'-title"]')->item(0)->textContent));
            $this->assertSame($showFilters ? [$branch->id] : [], $response->viewData('subcategories')->pluck('id')->all());
            $this->assertCount(1, $countQueries);
        }
    }

    public function test_header_menu_hides_empty_children_and_batches_product_presence_and_photo_translations(): void
    {
        config(['app.locale' => 'en', 'app.fallback_locale' => 'en',
            'herrera-category-photography.menu-leaf-photo' => 'assets/brand/herrera-logo.svg']);
        app()->setLocale('en');
        $root = $this->category('menu-root', locale: 'en', withProduct: false);
        $this->category('menu-empty', $root, locale: 'en', withProduct: false);
        $branch = $this->category('menu-branch', $root, locale: 'en', withProduct: false);
        $leaf = $this->category('menu-leaf', $branch, locale: 'en');
        $leaf->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG,
            'name' => 'Menu leaf photo', 'slug' => 'menu-leaf-photo']);
        app(SystemSettingsService::class)->put(NavigationMenuService::SETTINGS_KEY,
            [['type' => 'catalog', 'label' => 'Catalog', 'is_active' => true, 'show_dropdown' => true]]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $menu = app(NavigationMenuService::class)->forLocale('en');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $children = $menu[0]['children'][0]['children'];
        $this->assertSame(['menu-branch'], array_column($children, 'label'));
        $this->assertSame('menu-leaf', $children[0]['children'][0]['label']);
        $this->assertSame(asset('assets/brand/herrera-logo.svg'), $children[0]['children'][0]['image_url']);
        $this->assertCount(1, $queries->filter(fn ($query) => str_contains($query['query'], 'category_product')));
        $this->assertCount(1, $queries->filter(fn ($query) => str_contains($query['query'], 'category_translations')));
    }

    private function category(string $slug, ?Category $parent = null, array $attributes = [], string $locale = 'hr', bool $withProduct = true): Category
    {
        $category = Category::query()->create(array_replace([
            'code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true,
            'parent_id' => $parent?->id, 'sort_order' => 0,
        ], $attributes));
        $category->translations()->create(['locale' => $locale, 'scope' => $category->scope, 'name' => $slug, 'slug' => $slug]);
        if ($withProduct) {
            $product = Product::query()->create(['code' => 'product-'.$slug, 'is_active' => true, 'stock_qty' => 1, 'base_price' => 10]);
            $product->translations()->create(['locale' => $locale, 'name' => 'Product '.$slug, 'slug' => 'product-'.$slug]);
            $product->categories()->attach($category);
        }

        return $category;
    }

    private function cardIds(DOMXPath $xpath): array
    {
        return array_map(fn ($node) => (int) $node->getAttribute('data-subcategory-card'), iterator_to_array($xpath->query('//*[@data-subcategory-card]')));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
