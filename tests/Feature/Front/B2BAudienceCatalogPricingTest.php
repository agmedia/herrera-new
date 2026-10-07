<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\PriceCatalogQuery;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class B2BAudienceCatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
    }

    public function test_shop_and_category_filter_bounds_and_sort_use_only_the_customers_effective_prices(): void
    {
        [$user, $group, $other, $catalog, $category, $first, $second] = $this->fixture();
        $this->entry($catalog, $first, 'group', '90', $group);
        $this->entry($catalog, $second, 'group', '20', $group);
        $this->entry($catalog, $first, 'group', '1', $other);
        $this->activate($catalog);
        $this->actingAs($user);
        foreach ([route('shop.index'), route('categories.show', ['slug' => 'pricing-category'])] as $url) {
            $this->get($url.'?sort=price_low')->assertOk()
                ->assertViewHas('products', fn ($rows): bool => $rows->pluck('id')->all() === [$second->id, $first->id])
                ->assertViewHas('priceBounds', ['min' => 20.0, 'max' => 90.0]);
            $this->get($url.'?sort=price_high')->assertOk()
                ->assertViewHas('products', fn ($rows): bool => $rows->pluck('id')->all() === [$first->id, $second->id]);
            $this->get($url.'?price_min=50&price_max=100')->assertOk()
                ->assertViewHas('products', fn ($rows): bool => $rows->pluck('id')->all() === [$first->id]);
            $this->get($url.'?price_max=30')->assertOk()
                ->assertViewHas('products', fn ($rows): bool => $rows->pluck('id')->all() === [$second->id]);
        }
    }

    public function test_catalogue_query_exactly_matches_resolver_precedence_dates_quantity_priority_and_base(): void
    {
        [$user, $group, $other, $catalog, , $first, $second] = $this->fixture();
        $this->entry($catalog, $first, 'group', '80', $group);
        $this->entry($catalog, $first, 'customer', '70', customer: $user);
        $this->entry($catalog, $first, 'quantity', '60', $group, ['minimum_quantity' => 1, 'priority' => 5]);
        $this->entry($catalog, $first, 'quantity', '50', $group, ['minimum_quantity' => 10, 'priority' => 1]);
        $this->entry($catalog, $first, 'special', '44.5678', $group, ['priority' => 1]);
        $this->entry($catalog, $first, 'special', '30', $group, ['priority' => 2]);
        $this->entry($catalog, $first, 'special', '1', $other, ['priority' => 0]);
        $this->entry($catalog, $first, 'special', '5', $group, ['ends_at' => now()->subSecond()]);
        $this->entry($catalog, $second, 'base', '110.1234');
        $this->entry($catalog, $second, 'special', '1', $group, ['starts_at' => now()->addDay()]);
        $this->activate($catalog);
        $query = Product::query()->select('products.id');
        $expression = app(PriceCatalogQuery::class)->displayedPrice($query, $user);
        $rows = $query->selectRaw($expression['sql'].' as effective_price', $expression['bindings'])->get();
        foreach ($rows as $row) {
            $resolved = app(PriceCatalogResolver::class)->resolve($row, $user);
            $this->assertSame(round($resolved->price, 2), (float) $row->effective_price);
        }
        $this->assertSame(44.57, (float) $rows->firstWhere('id', $first->id)->effective_price);
        $this->assertSame(110.12, (float) $rows->firstWhere('id', $second->id)->effective_price);
    }

    public function test_snapshot_promotions_use_own_regular_price_and_do_not_fabricate_30day_history(): void
    {
        [$user, $group, $other, $catalog, , $first, $second] = $this->fixture();
        $this->entry($catalog, $first, 'group', '90', $group);
        $this->entry($catalog, $first, 'special', '100', $group);
        $this->entry($catalog, $first, 'special', '1', $other);
        $this->entry($catalog, $second, 'group', '20', $group);
        $this->entry($catalog, $second, 'customer', '15', customer: $user);
        $this->entry($catalog, $second, 'special', '10', $group);
        CatalogAction::query()->create(['code' => 'unused-action', 'scope' => 'product', 'type' => 'percentage', 'discount_value' => 50, 'target_type' => 'all', 'audience_type' => 'all', 'is_active' => true]);
        $this->activate($catalog);
        $this->actingAs($user);
        $this->get(route('shop.index', ['promo_only' => 1]))->assertOk()
            ->assertViewHas('products', fn ($rows): bool => $rows->pluck('id')->all() === [$second->id])
            ->assertViewHas('promoFilterAvailable', true);
        $price = app(ProductPricePresentationService::class)->forProduct($second, $user);
        $this->assertSame(10.0, $price['display_current']);
        $this->assertSame(15.0, $price['display_old']);
        $this->assertTrue($price['has_promotional_discount']);
        $this->assertSame(33, $price['discount_percent']);
        $this->assertNull($price['lowest_30_days_gross']);
        $this->assertNull($price['display_lowest_30_days']);
        $higher = app(ProductPricePresentationService::class)->forProduct($first, $user);
        $this->assertSame(100.0, $higher['display_current']);
        $this->assertFalse($higher['has_promotional_discount']);
        $this->assertNull($higher['display_old']);
    }

    public static function taxModes(): array
    {
        return [
            'storednet_displaynet' => [false, true, '90.0000', 90.0],
            'storednet_displaygross' => [false, false, '90.0000', 112.5],
            'storedgross_displaynet' => [true, true, '112.5000', 90.0],
            'storedgross_displaygross' => [true, false, '112.5000', 112.5],
            'fixedtax_storednet_displaygross' => [false, false, '90.0000', 95.0, 'fixed', 5],
            'fixedtax_storedgross_displaynet' => [true, true, '95.0000', 90.0, 'fixed', 5],
        ];
    }

    #[DataProvider('taxModes')]
    public function test_sql_matches_display_price_for_net_and_gross_tax_modes(bool $storedGross, bool $displayNet, string $stored, float $expected, string $rateType = 'percent', int $rateValue = 25): void
    {
        [$user, $group, , $catalog, , $first] = $this->fixture();
        config(['commerce.b2b_display_net' => $displayNet]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', $storedGross);
        $tax = TaxRate::query()->create(['code' => 'VAT25', 'name' => 'VAT25', 'rate_type' => $rateType, 'rate' => $rateValue, 'is_active' => true, 'is_default' => true]);
        $first->update(['tax_rate_id' => $tax->id, 'payload' => ['opencart' => ['tax_class_id' => 9]]]);
        $this->entry($catalog, $first, 'group', $stored, $group);
        $this->activate($catalog);
        $query = Product::query()->where('products.id', $first->id)->select('products.id');
        $expression = app(PriceCatalogQuery::class)->displayedPrice($query, $user);
        $sqlPrice = $query->selectRaw($expression['sql'].' as effective_price', $expression['bindings'])->sole();
        $this->assertSame($expected, (float) $sqlPrice->effective_price);
        $this->assertSame($expected, app(ProductPricePresentationService::class)->forProduct($first, $user)['display_current']);
    }

    public function test_sql_tax_conversion_respects_assigned_groups_tax_scope(): void
    {
        [$user, $group, , $catalog, , $first] = $this->fixture();
        config(['commerce.b2b_display_net' => false]);
        $group->update(['payload' => ['opencart' => ['tax_scope_defined' => true, 'taxable_class_ids' => [8]]]]);
        $tax = TaxRate::query()->create(['code' => 'VAT25', 'name' => 'VAT25', 'rate_type' => 'percent', 'rate' => 25, 'is_active' => true, 'is_default' => true]);
        $first->update(['tax_rate_id' => $tax->id, 'payload' => ['opencart' => ['tax_class_id' => 9]]]);
        $this->entry($catalog, $first, 'group', '90', $group);
        $this->activate($catalog);
        $query = Product::query()->where('products.id', $first->id)->select('products.id');
        $expression = app(PriceCatalogQuery::class)->displayedPrice($query, $user);
        $price = $query->selectRaw($expression['sql'].' as effective_price', $expression['bindings'])->sole();
        $this->assertSame(90.0, (float) $price->effective_price);
        $this->assertSame(90.0, app(ProductPricePresentationService::class)->forProduct($first, $user)['display_current']);
    }

    private function fixture(): array
    {
        $group = CustomerGroup::query()->create(['code' => 'primary', 'name' => 'Primary', 'is_active' => true]);
        $other = CustomerGroup::query()->create(['code' => 'other', 'name' => 'Other', 'is_active' => true]);
        $user = User::factory()->create();
        $user->customerGroups()->attach($other);
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Company', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'pricing-category', 'is_active' => true, 'show_in_menu' => true]);
        $category->translations()->create(['scope' => 'catalog', 'locale' => 'hr', 'name' => 'Pricing category', 'slug' => 'pricing-category']);
        $first = $this->product($category, 'FIRST', 10);
        $second = $this->product($category, 'SECOND', 100);
        $catalog = PriceCatalog::query()->create(['name' => 'Audience prices', 'status' => 'draft', 'currency_code' => 'EUR']);

        return [$user, $group, $other, $catalog, $category, $first, $second];
    }

    private function product(Category $category, string $code, float $base): Product
    {
        $product = Product::query()->create(['code' => $code, 'sku' => $code, 'base_price' => $base, 'stock_qty' => 50, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Product '.$code, 'slug' => strtolower($code)]);
        $product->categories()->attach($category->id, ['is_primary' => true, 'sort_order' => 0]);

        return $product;
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, ?CustomerGroup $group = null, array $extra = [], ?User $customer = null): void
    {
        $catalog->entries()->create($extra + ['source_key' => uniqid(), 'product_id' => $product->id, 'kind' => $kind, 'price' => $price, 'customer_group_id' => $group?->id, 'user_id' => $customer?->id, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }

    private function activate(PriceCatalog $catalog): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        app(PriceCatalogService::class)->activate($catalog, $admin);
    }
}
