<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Herrera48HourAvailabilityFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'catalog_hide_out_of_stock_products' => false,
            'catalog_use_manufacturers' => true,
        ]);
    }

    public function test_48_hour_filter_uses_local_stock_on_shop_category_and_manufacturer_pages(): void
    {
        $this->actingAs($this->customer());
        $category = $this->category('availability');
        $manufacturer = $this->manufacturer('availability');
        $local = $this->product('local', 220, 804, $category, $manufacturer);
        $supplier = $this->product('supplier', 0, 906, $category, $manufacturer);
        $this->product('empty', 0, 0, $category, $manufacturer);
        $inactive = $this->product('inactive', 12, 0, $category, $manufacturer);
        $inactive->update(['is_active' => false]);

        foreach ([route('shop.index'), route('categories.show', ['slug' => 'availability']), route('manufacturers.show', ['slug' => 'availability'])] as $url) {
            $this->get($url.'?available_only=1&sort=stock_high')->assertOk()
                ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$local->id])
                ->assertSee('Prikaži dostupne u 48 h')->assertDontSee('Prikaži samo odmah dostupne');
        }

        // Supplier stock stays orderable and visible when the local-delivery filter is off.
        $this->get(route('shop.index', ['sort' => 'stock_high']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->take(2)->all() === [$local->id, $supplier->id]);
        $this->get(route('products.show', ['slug' => 'supplier']))->assertOk()
            ->assertViewHas('product', fn (Product $product) => $product->supplier_stock_qty === 906 && $product->storefrontIsPurchasable());
    }

    public function test_local_filter_facets_and_counts_exclude_supplier_only_products_even_after_general_stock_cache_is_warm(): void
    {
        $this->actingAs($this->customer());
        app(SystemSettingsService::class)->put('catalog_hide_out_of_stock_products', true);
        $root = $this->category('availability');
        $localCategory = $this->category('local-category', $root);
        $supplierCategory = $this->category('supplier-category', $root);
        $supplierRoot = $this->category('supplier-root');
        $localManufacturer = $this->manufacturer('local-brand');
        $supplierManufacturer = $this->manufacturer('supplier-brand');
        $local = $this->product('local', 220, 804, $localCategory, $localManufacturer);
        $supplier = $this->product('supplier', 0, 906, $supplierCategory, $supplierManufacturer);
        $supplier->categories()->attach($supplierRoot);
        $option = $this->option();
        $localValue = $this->optionValue($local, $option, 'local-finish', 0);
        $this->optionValue($supplier, $option, 'supplier-finish', 0);
        $localMaterial = $this->attribute('local-material');
        $supplierMaterial = $this->attribute('supplier-material');
        $sharedMaterial = $this->attribute('shared-material');
        $local->attributes()->attach([$localMaterial->id, $sharedMaterial->id]);
        $supplier->attributes()->attach([$supplierMaterial->id, $sharedMaterial->id]);
        app(SystemSettingsService::class)->putMany([
            'store_product_filter_option_ids' => [$option->id],
            'store_product_filter_attribute_group_codes' => ['material'],
        ]);

        $this->get(route('categories.show', ['slug' => 'availability']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->total() === 2)
            ->assertSee('supplier-finish')->assertSee('supplier-material');
        $this->get(route('shop.index'))->assertOk()
            ->assertViewHas('categories', fn ($categories) => $categories->contains('id', $supplierRoot->id));

        $url = route('categories.show', ['slug' => 'availability', 'available_only' => 1, 'attr_material' => $sharedMaterial->id]);
        $this->get($url)->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$local->id])
            ->assertViewHas('optionFilters', fn ($filters) => $filters[0]['values'] === [
                ['id' => $localValue->id, 'label' => 'local-finish', 'count' => 1, 'swatch_image_url' => null],
            ])
            ->assertViewHas('manufacturers', fn ($manufacturers) => $manufacturers->pluck('id')->all() === [$localManufacturer->id])
            ->assertViewHas('subcategories', fn ($categories) => $categories->firstWhere('id', $localCategory->id)->products_count === 1
                && $categories->firstWhere('id', $supplierCategory->id)->products_count === 0)
            ->assertDontSee('supplier-finish')->assertDontSee('supplier-material');

        $this->get(route('categories.show', ['slug' => 'availability', 'available_only' => 1, 'opt_'.$option->id => $localValue->id]))->assertOk()
            ->assertViewHas('attributeFilters', fn ($filters) => collect($filters[0]['values'])->pluck('id')->all() === [$localMaterial->id, $sharedMaterial->id]);
        $this->get(route('shop.index', ['available_only' => 1]))->assertOk()
            ->assertViewHas('categories', fn ($categories) => ! $categories->contains('id', $supplierRoot->id)
                && $categories->firstWhere('id', $root->id)->products_count === 1);
        $this->get(route('manufacturers.show', ['slug' => 'supplier-brand', 'available_only' => 1]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->total() === 0)
            ->assertViewHas('categories', fn ($categories) => $categories->isEmpty());
    }

    public function test_active_local_variant_stock_is_included_but_inactive_variants_and_supplier_stock_are_excluded(): void
    {
        $this->actingAs($this->customer());
        $category = $this->category('availability');
        $available = $this->product('available-variant', 0, 500, $category);
        $inactive = $this->product('inactive-variant', 0, 600, $category);
        $empty = $this->product('empty-variant', 0, 700, $category);
        $option = $this->option();
        $this->optionValue($available, $option, 'available-size', 2);
        $this->optionValue($inactive, $option, 'inactive-size', 20, false);
        $this->optionValue($empty, $option, 'empty-size', 0);

        $this->get(route('categories.show', ['slug' => 'availability', 'available_only' => 1]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$available->id])
            ->assertSee('available-size')->assertDontSee('inactive-size')->assertDontSee('empty-size');
    }

    public function test_other_storefront_keeps_supplier_availability_and_its_existing_label(): void
    {
        $this->actingAs($this->customer());
        app(SystemSettingsService::class)->put('store_brand_name', 'Termol');
        $category = $this->category('availability');
        $supplier = $this->product('supplier', 0, 906, $category);
        $local = $this->product('local', 220, 0, $category);
        foreach ([route('shop.index'), route('categories.show', ['slug' => 'availability'])] as $url) {
            $this->get($url.'?available_only=1&sort=stock_high')->assertOk()
                ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$supplier->id, $local->id])
                ->assertSee('Prikaži samo odmah dostupne')->assertDontSee('Prikaži dostupne u 48 h');
        }
    }

    public function test_b2b_guest_cannot_use_the_local_availability_toggle_or_obtain_stock_and_price_details(): void
    {
        $category = $this->category('availability');
        $this->product('local', 220, 804, $category);
        $this->product('supplier', 0, 906, $category);
        foreach ([route('shop.index'), route('categories.show', ['slug' => 'availability'])] as $url) {
            $this->get($url.'?available_only=1')->assertOk()
                ->assertViewHas('products', fn ($products) => $products->total() === 2)
                ->assertViewHas('filters', fn ($filters) => ! $filters['available_only'])
                ->assertDontSee('name="available_only"', false)->assertDontSee('1234.56', false);
        }
    }

    private function product(string $code, int $local, int $supplier, Category $category, ?Manufacturer $manufacturer = null): Product
    {
        $product = Product::query()->create([
            'code' => $code, 'sku' => $code, 'base_price' => 1234.56, 'stock_qty' => $local,
            'supplier_stock_qty' => $supplier, 'manufacturer_id' => $manufacturer?->id, 'is_active' => true,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        $product->categories()->attach($category);

        return $product;
    }

    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = Category::query()->create(['code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true, 'parent_id' => $parent?->id]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => $slug, 'slug' => $slug]);

        return $category;
    }

    private function manufacturer(string $slug): Manufacturer
    {
        $manufacturer = Manufacturer::query()->create(['code' => $slug, 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $slug, 'slug' => $slug]);

        return $manufacturer;
    }

    private function option(): Option
    {
        $option = Option::query()->create(['code' => 'size', 'type' => Option::TYPE_SELECT, 'is_active' => true]);
        $option->translations()->create(['locale' => 'hr', 'name' => 'Veličina', 'slug' => 'size']);

        return $option;
    }

    private function optionValue(Product $product, Option $option, string $code, int $stock, bool $active = true): OptionValue
    {
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => $code, 'is_active' => true]);
        $value->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        ProductOptionValue::query()->create([
            'product_id' => $product->id, 'option_value_id' => $value->id, 'mode' => 'single',
            'sku' => $code, 'stock_qty' => $stock, 'is_active' => $active, 'combination_hash' => hash('sha256', $code),
        ]);

        return $value;
    }

    private function attribute(string $code): Attribute
    {
        $attribute = Attribute::query()->create(['code' => $code, 'group_code' => 'material', 'type' => Attribute::TYPE_SELECT, 'is_active' => true]);
        $attribute->translations()->create(['locale' => 'hr', 'group_name' => 'Materijal', 'name' => $code, 'slug' => $code]);

        return $attribute;
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'approved', 'name' => 'Approved', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        B2BAccount::query()->create([
            'user_id' => $user->id, 'company_name' => 'Test d.o.o.', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
        ]);

        return $user;
    }
}
