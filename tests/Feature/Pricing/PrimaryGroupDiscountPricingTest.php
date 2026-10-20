<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Pricing\CustomerDiscountService;
use App\Services\Pricing\PriceCatalogQuery;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PrimaryGroupDiscountService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class PrimaryGroupDiscountPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
    }

    public function test_primary_default_prices_new_products_and_existing_base_only_products_with_batch_query_and_cart_parity(): void
    {
        [$buyer, $group, $catalog] = $this->fixture();
        $new = $this->product('120.1234');
        $baseOnly = $this->product('250');
        $this->entry($catalog, $baseOnly, PriceCatalogEntry::BASE, '100');
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts([$new, $baseOnly], $buyer);
        $this->assertSame(60.0617, $resolver->resolve($new, $buyer)->price);
        $this->assertSame(50.0, $resolver->resolve($baseOnly, $buyer)->price);
        $this->assertSame('price_catalog_primary_group_default', $resolver->resolve($new, $buyer)->source_type);
        $this->assertTrue($resolver->resolve($new, $buyer)->is_final);
        $this->assertNull($resolver->resolve($baseOnly, $buyer)->catalog_entry_id);
        $this->assertPrices($buyer, [$new->id => 60.0617, $baseOnly->id => 50.0]);
        $this->actingAs($buyer);
        CatalogAction::query()->create(['code' => 'DO-NOT-STACK', 'scope' => 'product', 'type' => 'percentage', 'discount_value' => 50, 'target_type' => 'all', 'audience_type' => 'all', 'is_active' => true]);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($new, 3));
        $line = $cart->lines()->sole();
        $this->assertSame(60.0617, $line['unit_price']);
        $this->assertSame(180.1851, $line['line_total']);
        $this->assertSame('price_catalog_primary_group_default', $line['b2b_source_type']);
        $this->assertNull($line['action_code']);
    }

    public function test_existing_exact_group_customer_special_and_quantity_agreements_keep_their_precedence(): void
    {
        [$buyer, $group, $catalog] = $this->fixture();
        $groupProduct = $this->product();
        $customerProduct = $this->product();
        $specialProduct = $this->product();
        $quantityProduct = $this->product();
        foreach ([$groupProduct, $customerProduct, $specialProduct, $quantityProduct] as $product) {
            $this->entry($catalog, $product, PriceCatalogEntry::BASE, '100');
        }
        $this->entry($catalog, $groupProduct, PriceCatalogEntry::GROUP, '70', $group);
        $this->entry($catalog, $customerProduct, PriceCatalogEntry::CUSTOMER, '80', customer: $buyer);
        $this->entry($catalog, $specialProduct, PriceCatalogEntry::SPECIAL, '60', $group);
        $this->entry($catalog, $quantityProduct, PriceCatalogEntry::QUANTITY, '55', $group);
        $resolver = app(PriceCatalogResolver::class);
        $expected = [$groupProduct->id => 70.0, $customerProduct->id => 80.0, $specialProduct->id => 60.0, $quantityProduct->id => 55.0];
        foreach ([$groupProduct, $customerProduct, $specialProduct, $quantityProduct] as $product) {
            $this->assertSame($expected[$product->id], $resolver->resolve($product, $buyer)->price);
        }
        $this->assertSame(50.0, $resolver->resolve($specialProduct, $buyer)->previous_price);
        $this->assertPrices($buyer, $expected);
        $promotions = Product::query();
        app(PriceCatalogQuery::class)->applyPromotionFilter($promotions, $buyer);
        $this->assertSame([], $promotions->pluck('products.id')->all());
    }

    public function test_explicit_customer_and_native_group_rules_override_primary_default(): void
    {
        [$buyer, $group, $catalog] = $this->fixture();
        $product = $this->product();
        $this->entry($catalog, $product, PriceCatalogEntry::BASE, '100');
        DB::table('catalog_group_discount_rules')->insert([
            'price_catalog_id' => $catalog->id, 'name' => 'Current group rule', 'percent' => 20,
            'customer_group_ids' => json_encode([$group->id]), 'manufacturer_ids' => '[]', 'category_ids' => '[]', 'excluded_product_ids' => '[]',
            'is_active' => true, 'include_descendants' => true, 'priority' => 0,
        ]);
        $resolver = app(PriceCatalogResolver::class);
        $this->assertSame(80.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 80.0]);
        $actor = User::factory()->create();
        Bouncer::assign('superadmin')->to($actor);
        app(CustomerDiscountService::class)->saveRule($buyer, ['name' => 'Personal contract', 'percent' => 60], $actor);
        $this->assertSame(40.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 40.0]);
    }

    public function test_unknown_and_secondary_group_names_do_not_create_a_discount_and_zero_and_full_defaults_are_valid(): void
    {
        [$buyer, $group, $catalog] = $this->fixture();
        $group->update(['default_discount_percent' => null]);
        $buyer->b2bAccount->setRelation('customerGroup', $group);
        $other = CustomerGroup::query()->create(['code' => 'secondary', 'name' => 'B2B90', 'default_discount_percent' => 90, 'is_active' => true]);
        $buyer->customerGroups()->attach($other);
        $product = $this->product();
        $this->assertSame(100.0, app(PriceCatalogResolver::class)->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 100.0]);
        foreach ([0 => 100.0, 100 => 0.0] as $percent => $expected) {
            $group->update(['default_discount_percent' => $percent]);
            app(PriceCatalogResolver::class)->forgetActiveCatalog();
            $this->assertSame($expected, app(PriceCatalogResolver::class)->resolve($product, $buyer)->price);
            $this->assertPrices($buyer, [$product->id => $expected]);
        }
        $group->payload = ['classification_type' => CustomerGroup::CLASSIFICATION_INDUSTRY];
        $this->assertNull(app(PrimaryGroupDiscountService::class)->percent($group));
    }

    public function test_migration_backfills_only_unambiguous_explicit_imported_descriptions(): void
    {
        $migration = require database_path('migrations/2026_10_20_150000_add_primary_group_default_discount.php');
        $migration->down();
        $make = fn (string $name, array $descriptions) => CustomerGroup::query()->create([
            'code' => uniqid('imported-'), 'name' => $name, 'is_active' => true,
            'payload' => ['translations' => array_map(fn (string $description) => ['description' => $description], $descriptions)],
        ]);
        $explicit = $make('Any name', ['Kupci sa 50% rabata', 'Kupci sa 50% rabata']);
        $case = $make('Any name', ['kupci sa 38% rabata']);
        $nameOnly = $make('B2B60', ['']);
        $mixed = $make('Timelektro B2B55', ["Buziri 60%\nsve ostalo 55%"]);
        $conflicting = $make('Conflicting', ['Kupci sa 40% rabata', 'Kupci sa 50% rabata']);
        $invalid = $make('Invalid', ['Kupci sa 101% rabata']);
        $migration->up();
        $this->assertSame('50.0000', $explicit->fresh()->default_discount_percent);
        $this->assertSame('38.0000', $case->fresh()->default_discount_percent);
        foreach ([$nameOnly, $mixed, $conflicting, $invalid] as $unknown) {
            $this->assertNull($unknown->fresh()->default_discount_percent);
        }
    }

    private function fixture(): array
    {
        $group = CustomerGroup::query()->create(['code' => 'primary', 'name' => 'B2B50', 'is_active' => true, 'default_discount_percent' => 50]);
        $buyer = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $buyer->id, 'company_name' => 'Test', 'oib' => '12345678901', 'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id]);
        $catalog = PriceCatalog::query()->create(['name' => 'Active catalog', 'status' => PriceCatalog::DRAFT, 'currency_code' => 'EUR']);
        $catalog->update(['status' => PriceCatalog::ACTIVE]);
        DB::table('catalog_price_catalog_state')->where('id', 1)->update(['price_catalog_id' => $catalog->id]);

        return [$buyer, $group, $catalog];
    }

    private function product(string $price = '100'): Product
    {
        return Product::query()->create(['code' => uniqid('product-'), 'base_price' => $price, 'stock_qty' => 100, 'is_active' => true]);
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, ?CustomerGroup $group = null, ?User $customer = null): void
    {
        DB::table('catalog_price_entries')->insert(['price_catalog_id' => $catalog->id, 'source_key' => uniqid('entry-'), 'product_id' => $product->id, 'kind' => $kind, 'price' => $price, 'customer_group_id' => $group?->id, 'user_id' => $customer?->id, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }

    private function assertPrices(User $buyer, array $expected): void
    {
        $price = (new PriceCatalogQuery)->storedPrice($buyer);
        $actual = Product::query()->whereKey(array_keys($expected))->select('products.id')
            ->selectRaw($price['sql'].' as resolved_price', $price['bindings'])->get()
            ->mapWithKeys(fn (Product $product) => [$product->id => (float) $product->resolved_price])->all();
        $this->assertEquals($expected, $actual);
    }
}
