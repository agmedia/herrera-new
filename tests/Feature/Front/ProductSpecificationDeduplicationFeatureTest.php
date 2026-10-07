<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\CatalogProductSpecification;
use App\Models\Catalog\Product\Product;
use App\Support\ProductSpecificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSpecificationDeduplicationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_canonical_and_imported_specs_are_displayed_once_without_removing_source_records(): void
    {
        $product = $this->product();
        $this->attribute($product, 'snaga', 'Snaga', '5 W');
        $this->specification($product, 'Snaga', ['5'], 'W');

        $this->get(route('products.show', ['slug' => 'specification-product']))->assertOk()
            ->assertSee('data-product-attribute-panels', false)
            ->assertSee('5 W')
            ->assertDontSee('data-product-technical-specifications', false);

        $this->assertSame(1, $product->attributes()->count());
        $this->assertSame(1, $product->technicalSpecificationRows()->count());
    }

    public function test_distinct_labels_values_and_units_remain_visible(): void
    {
        $product = $this->product();
        $this->attribute($product, 'snaga', 'Snaga', '5 W');
        $this->specification($product, ' SNAGA ', [' 5 '], 'W');
        $alternateValue = $this->specification($product, 'Snaga', ['7'], 'W');
        $alternateLabel = $this->specification($product, 'Potrošnja', ['5'], 'W');
        $extra = $this->specification($product, 'Temperatura', ['4000 K']);
        $rows = app(ProductSpecificationPresenter::class)->remainingRows($this->loaded($product), 'hr', 'hr');

        $this->assertSame([$alternateValue->id, $alternateLabel->id, $extra->id], $rows->pluck('id')->all());
        $this->get(route('products.show', ['slug' => 'specification-product']))->assertOk()
            ->assertSee('data-product-technical-specifications', false)
            ->assertSee('7 W')->assertSee('Potrošnja')->assertSee('4000 K');
    }

    public function test_multi_value_panels_and_repeated_import_rows_do_not_repeat_identical_pairs(): void
    {
        $product = $this->product();
        $this->attribute($product, 'boja', 'Boja', 'Bijela');
        $this->attribute($product, 'boja', 'Boja', 'Crna');
        $this->specification($product, 'Boja', ['Bijela', 'Crna']);
        $additional = $this->specification($product, 'Napon', ['230 V']);
        $this->specification($product, 'Napon', ['230 V']);

        $rows = app(ProductSpecificationPresenter::class)->remainingRows($this->loaded($product), 'hr', 'hr');
        $this->assertSame([$additional->id], $rows->pluck('id')->all());
        $this->assertSame(3, $product->technicalSpecificationRows()->count());
    }

    public function test_hidden_msan_attributes_do_not_hide_their_technical_specifications(): void
    {
        $product = $this->product();
        $this->attribute($product, 'msan-snaga', 'Snaga', '5 W', Attribute::SOURCE_MSAN_SPECIFICATION);
        $specification = $this->specification($product, 'Snaga', ['5 W']);

        $rows = app(ProductSpecificationPresenter::class)->remainingRows($this->loaded($product), 'hr', 'hr');
        $this->assertSame([$specification->id], $rows->pluck('id')->all());
        $this->get(route('products.show', ['slug' => 'specification-product']))->assertOk()
            ->assertDontSee('data-product-attribute-panels', false)
            ->assertSee('data-product-technical-specifications', false)->assertSee('5 W');
    }

    public function test_deduplication_uses_the_same_locale_as_the_attribute_panel(): void
    {
        $product = $this->product();
        $attribute = $this->attribute($product, 'snaga', 'Snaga', '5 W');
        $attribute->translations()->create(['locale' => 'en', 'group_name' => 'Power', 'name' => '5 W', 'slug' => $attribute->code.'-en']);
        $croatian = $this->specification($product, 'Snaga', ['5 W']);
        $this->specification($product, 'Power', ['5 W']);

        $rows = app(ProductSpecificationPresenter::class)->remainingRows($this->loaded($product), 'en', 'hr');
        $this->assertSame([$croatian->id], $rows->pluck('id')->all());
    }

    private function product(): Product
    {
        $product = Product::query()->create(['code' => 'SPEC-PRODUCT', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 1]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Specification product', 'slug' => 'specification-product']);

        return $product;
    }

    private function attribute(Product $product, string $group, string $label, string $value, string $source = Attribute::SOURCE_MANUAL): Attribute
    {
        $attribute = Attribute::query()->create([
            'code' => $group.'-'.$product->attributes()->count(), 'group_code' => $group,
            'type' => Attribute::TYPE_MULTI, 'is_active' => true, 'payload' => ['source' => $source],
        ]);
        $attribute->translations()->create(['locale' => 'hr', 'group_name' => $label, 'name' => $value, 'slug' => $attribute->code]);
        $product->attributes()->attach($attribute);

        return $attribute;
    }

    private function specification(Product $product, string $label, array $values, string $measure = ''): CatalogProductSpecification
    {
        return $product->technicalSpecificationRows()->create([
            'source' => 'opencart', 'source_key' => 'spec-'.$product->technicalSpecificationRows()->count(),
            'group_name' => 'SPECIFIKACIJE', 'item_name' => $label, 'values' => $values, 'measure' => $measure,
        ]);
    }

    private function loaded(Product $product): Product
    {
        return $product->load(['attributes.translations', 'technicalSpecificationRows']);
    }
}
