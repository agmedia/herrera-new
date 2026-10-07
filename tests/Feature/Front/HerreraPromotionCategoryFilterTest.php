<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraPromotionCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'store_pricing_prices_include_tax' => false,
            'catalog_hide_out_of_stock_products' => false,
            'catalog_use_manufacturers' => true,
        ]);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_promotion_categories_and_recursive_counts_follow_customer_prices_after_general_cache_is_warm(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['buyer']);

        $this->get(route('shop.index'))->assertOk()
            ->assertViewHas('products', fn ($products): bool => $products->total() === 6)
            ->assertViewHas('categories', fn ($categories): bool => $categories->count() === 4
                && (int) $categories->firstWhere('id', $fixture['root']->id)->products_count === 2);

        $response = $this->get(route('shop.index', ['promo_only' => 1]))->assertOk();
        $this->assertSame([$fixture['sale']->id], $response->viewData('products')->pluck('id')->all());
        $this->assertSame([$fixture['root']->id], $response->viewData('categories')->pluck('id')->all());
        $this->assertSame(1, (int) $response->viewData('categories')->first()->products_count);

        // Switching buyers must not reuse another customer's promotion category counts.
        $response = $this->actingAs($fixture['otherBuyer'])->get(route('shop.index', ['promo_only' => 1]))->assertOk();
        $this->assertSame([$fixture['otherSale']->id], $response->viewData('products')->pluck('id')->all());
        $this->assertSame([$fixture['otherRoot']->id], $response->viewData('categories')->pluck('id')->all());
        $this->assertSame(1, (int) $response->viewData('categories')->first()->products_count);
    }

    public function test_desktop_and_mobile_category_navigation_keeps_promotions_through_children_and_breadcrumbs(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['buyer']);

        foreach ([
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1',
        ] as $userAgent) {
            $response = $this->withHeaders(['User-Agent' => $userAgent])->get(route('shop.index', ['promo_only' => 1]))->assertOk();
            $rootUrl = route('categories.show', ['slug' => 'promo-lighting', 'promo_only' => 1]);
            $this->assertCategoryNavigation($response, $rootUrl, route('shop.index', ['promo_only' => 1]));

            $response = $this->get($rootUrl)->assertOk();
            $this->assertOnlySale($response, $fixture['sale']);
            $this->assertSame([$fixture['child']->id], $response->viewData('subcategories')->pluck('id')->all());
            $this->assertSame(1, (int) $response->viewData('subcategories')->first()->products_count);
            $childUrl = route('categories.show', ['slug' => 'promo-led', 'promo_only' => 1]);
            $this->assertCategoryNavigation($response, $childUrl, $rootUrl);

            $response = $this->get($childUrl)->assertOk();
            $this->assertOnlySale($response, $fixture['sale']);
            $leafUrl = route('categories.show', ['slug' => 'promo-bulbs', 'promo_only' => 1]);
            $this->assertCategoryNavigation($response, $leafUrl, $childUrl);

            $response = $this->get($leafUrl)->assertOk();
            $this->assertOnlySale($response, $fixture['sale']);
            $breadcrumbLinks = $this->xpath($response)->query('//nav[@aria-label="Breadcrumb"]//a');
            $hrefs = [];
            foreach ($breadcrumbLinks as $link) {
                $hrefs[] = $link->getAttribute('href');
            }
            $this->assertContains($rootUrl, $hrefs);
            $this->assertContains($childUrl, $hrefs);
        }
    }

    public function test_manufacturer_promotion_categories_preserve_both_promotion_and_brand_when_followed(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['buyer']);
        $this->get(route('manufacturers.show', ['slug' => 'promo-brand']))->assertOk()
            ->assertViewHas('categories', fn ($categories): bool => $categories->count() === 4);

        $response = $this->get(route('manufacturers.show', ['slug' => 'promo-brand', 'promo_only' => 1]))->assertOk();
        $this->assertOnlySale($response, $fixture['sale']);
        $this->assertSame([$fixture['root']->id], $response->viewData('categories')->pluck('id')->all());
        $this->assertSame(1, (int) $response->viewData('categories')->first()->products_count);
        $rootUrl = route('categories.show', ['slug' => 'promo-lighting', 'manufacturer' => 'promo-brand', 'promo_only' => 1]);
        $this->assertCategoryNavigation($response, $rootUrl, route('manufacturers.show', ['slug' => 'promo-brand', 'promo_only' => 1]));
        $this->assertOnlySale($this->get($rootUrl)->assertOk(), $fixture['sale']);
    }

    public function test_legacy_catalog_actions_also_limit_categories_to_active_promotion_products(): void
    {
        config(['commerce.b2b_only' => false]);
        $root = $this->category('action-lighting');
        $child = $this->category('action-led', $root);
        $regularRoot = $this->category('action-regular');
        $sale = $this->product('action-sale', $child);
        $sale->categories()->attach($root);
        $this->product('action-full-price', $child);
        $this->product('action-regular-product', $regularRoot);
        CatalogAction::query()->create([
            'code' => 'category-navigation-sale',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 20,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ])->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $sale->id, 'sort_order' => 0]);

        $this->get(route('shop.index'))->assertOk()
            ->assertViewHas('categories', fn ($categories): bool => $categories->count() === 2);
        $response = $this->get(route('shop.index', ['promo_only' => 1]))->assertOk();
        $this->assertOnlySale($response, $sale);
        $this->assertSame([$root->id], $response->viewData('categories')->pluck('id')->all());
        $this->assertSame(1, (int) $response->viewData('categories')->first()->products_count);
        $response = $this->get(route('categories.show', ['slug' => 'action-lighting', 'promo_only' => 1]))->assertOk();
        $this->assertOnlySale($response, $sale);
        $this->assertSame([$child->id], $response->viewData('subcategories')->pluck('id')->all());
        $this->assertSame(1, (int) $response->viewData('subcategories')->first()->products_count);
    }

    private function assertOnlySale(TestResponse $response, Product $sale): void
    {
        $this->assertSame(1, $response->viewData('products')->total());
        $this->assertSame([$sale->id], $response->viewData('products')->pluck('id')->all());
        $this->assertTrue($response->viewData('filters')['promo_only']);
    }

    private function assertCategoryNavigation(TestResponse $response, string $categoryUrl, string $currentUrl): void
    {
        $xpath = $this->xpath($response);
        foreach (['catalog-sidebar-category-options', 'catalog-mobile-filter-options'] as $class) {
            $links = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]/a');
            $this->assertSame(2, $links->count(), 'The category filter should contain the current category and its single promotion category.');
            $this->assertSame($currentUrl, $links->item(0)->getAttribute('href'));
            $this->assertSame($categoryUrl, $links->item(1)->getAttribute('href'));
        }
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    private function fixture(): array
    {
        $group = CustomerGroup::query()->create(['code' => 'promotion-navigation', 'name' => 'Primary', 'is_active' => true]);
        $otherGroup = CustomerGroup::query()->create(['code' => 'promotion-navigation-other', 'name' => 'Other', 'is_active' => true]);
        $buyer = $this->buyer($group);
        $otherBuyer = $this->buyer($otherGroup);
        $root = $this->category('promo-lighting');
        $child = $this->category('promo-led', $root);
        $leaf = $this->category('promo-bulbs', $child);
        $this->category('promo-no-sale', $root);
        $otherRoot = $this->category('promo-other-buyer');
        $regularRoot = $this->category('promo-regular');
        $timedRoot = $this->category('promo-time-boundaries');
        $manufacturer = Manufacturer::query()->create(['code' => 'promo-brand', 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => 'Promotion brand', 'slug' => 'promo-brand']);
        $sale = $this->product('promo-sale', $leaf, $manufacturer);
        $sale->categories()->attach($root);
        $regular = $this->product('promo-full-price', $root->children()->where('code', 'promo-no-sale')->first(), $manufacturer);
        $otherSale = $this->product('promo-other-sale', $otherRoot, $manufacturer);
        $regularOnly = $this->product('promo-regular-only', $regularRoot, $manufacturer);
        $future = $this->product('promo-future', $timedRoot, $manufacturer);
        $expired = $this->product('promo-expired', $timedRoot, $manufacturer);
        $catalog = PriceCatalog::query()->create(['name' => 'Category promotions', 'status' => 'draft', 'currency_code' => 'EUR']);
        foreach ([$sale, $regular, $otherSale, $regularOnly, $future, $expired] as $product) {
            foreach ([$group, $otherGroup] as $priceGroup) {
                $this->entry($catalog, $product, $priceGroup, PriceCatalogEntry::GROUP, 80);
            }
        }
        $this->entry($catalog, $sale, $group, PriceCatalogEntry::SPECIAL, 40);
        $this->entry($catalog, $otherSale, $otherGroup, PriceCatalogEntry::SPECIAL, 30);
        $this->entry($catalog, $future, $group, PriceCatalogEntry::SPECIAL, 10, ['starts_at' => now()->addHour()]);
        $this->entry($catalog, $expired, $group, PriceCatalogEntry::SPECIAL, 10, ['ends_at' => now()->subHour()]);
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        app(PriceCatalogService::class)->activate($catalog, $admin);

        return compact('buyer', 'otherBuyer', 'root', 'child', 'leaf', 'otherRoot', 'sale', 'otherSale');
    }

    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = Category::query()->create(['code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true, 'parent_id' => $parent?->id]);
        $category->translations()->create(['scope' => Category::SCOPE_CATALOG, 'locale' => 'hr', 'name' => $slug, 'slug' => $slug]);

        return $category;
    }

    private function product(string $code, Category $category, ?Manufacturer $manufacturer = null): Product
    {
        $product = Product::query()->create(['code' => $code, 'base_price' => 100, 'stock_qty' => 10, 'manufacturer_id' => $manufacturer?->id, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        $product->categories()->attach($category);

        return $product;
    }

    private function buyer(CustomerGroup $group): User
    {
        $user = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Test company', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);

        return $user;
    }

    private function entry(PriceCatalog $catalog, Product $product, CustomerGroup $group, string $kind, float $price, array $extra = []): void
    {
        $catalog->entries()->create($extra + [
            'source_key' => $product->code.'-'.$group->code.'-'.$kind,
            'product_id' => $product->id,
            'kind' => $kind,
            'price' => $price,
            'customer_group_id' => $group->id,
            'minimum_quantity' => 1,
            'priority' => 0,
            'is_active' => true,
        ]);
    }
}
