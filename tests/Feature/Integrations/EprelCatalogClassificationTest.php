<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Eprel\EprelCatalogProductMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EprelCatalogClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    #[DataProvider('classificationCases')]
    public function test_only_evidenced_categories_and_names_add_a_search_scope(array $case, array $expected): void
    {
        $product = $this->product($case);
        $before = $product->fresh()->getAttributes();

        $criteria = app(EprelCatalogProductMatcher::class)->criteria($product);

        $this->assertSame($expected, $criteria['groups']);
        $this->assertSame(['SOURCE-MODEL', 'LOCAL-SKU'], $criteria['models']);
        $this->assertSame(['5949097723490'], $criteria['gtins']);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertFalse($product->fresh()->energy_label_required);
        $this->assertNull($product->fresh()->energy_efficiency_class);
        $this->assertSame(0, $product->energyDeclarations()->count());
        Http::assertNothingSent();
    }

    public static function classificationCases(): array
    {
        $extra = ['category_code' => 'herrera-oc-category-824', 'category_name' => 'Extra popusti'];
        $fan = ['category_code' => 'herrera-oc-category-1236', 'category_name' => 'Stropni ventilatori'];
        $small = ['category_code' => 'herrera-oc-category-1083', 'category_name' => 'Mali kućanski aparati'];
        $light = ['name' => 'Žarulja LED 10W E27 4000K', 'energy_values' => [' F']];

        return [
            'water heater native category code' => [['category_code' => 'herrera-oc-category-1025', 'category_name' => 'Bojleri', 'name' => 'Bojler električni Midea M-D30-15F6', 'brand' => 'Midea'], ['waterheaters']],
            'water heater exact Croatian category alias' => [['category_name' => 'Električni bojleri', 'name' => 'Bojler električni'], ['waterheaters']],
            'a non-catalogue category is not a heater scope' => [['category_code' => 'herrera-oc-category-1025', 'category_name' => 'Električni bojleri', 'scope' => 'page'], []],
            'extra sale LED with original energy evidence' => [$extra + $light, ['lightsources']],
            'extra sale luminaire with the other supported source label' => [$extra + ['name' => 'Stropna linearna svjetiljka 27W BARY', 'energy_values' => ['E'], 'energy_label' => 'Klasa energetske učinkovitosti EEi'], ['lightsources']],
            'ceiling fan with integrated lighting and source evidence' => [$fan + ['name' => 'Ventilator stropni sa osvjetljenjem 40+20W BRAYTRON', 'energy_values' => ['E']], ['lightsources']],
            'ceiling fan with no lighting is not a light source' => [$fan + ['name' => 'Ventilator stropni 40W BRAYTRON', 'energy_values' => ['E']], []],
            'lighting name alone cannot replace original energy evidence' => [$extra + ['name' => $light['name']], []],
            'a supplier attribute is not original Herrera evidence' => [$extra + $light + ['energy_source' => 'msan'], []],
            'an unrelated attribute label is not energy evidence' => [$extra + $light + ['energy_label' => 'Snaga'], []],
            'an invalid class is not source evidence' => [$extra + ['name' => $light['name'], 'energy_values' => ['F-not-a-class']], []],
            'an old plus-scale class is not current A-to-G evidence' => [$extra + ['name' => $light['name'], 'energy_values' => ['A+']], []],
            'contradictory imported classes need manual review' => [$extra + ['name' => $light['name'], 'energy_values' => ['E', 'F']], []],
            'a television bracket is not a light source' => [$extra + ['name' => 'Nosač za TV LED', 'energy_values' => ['F']], []],
            'a lighting tool is not a light source' => [$extra + ['name' => 'Kliješta za LED rasvjetu', 'energy_values' => ['F']], []],
            'unrelated categories are not inferred from an energy-looking title' => [$light + ['category_name' => 'Alati'], []],
            'Esper mini oven has a narrow search scope' => [$small + ['brand' => 'Esper', 'name' => 'Mini pećnica NAPOLI 25L 1600W ESP EKO006N'], ['ovens']],
            'microwave is not inferred as an oven' => [$small + ['brand' => 'Esper', 'name' => 'Mikrovalna pećnica HORNEADO 20L EKO009'], []],
            'other small appliances are not inferred as ovens' => [$small + ['brand' => 'Esper', 'name' => 'Kuhalo za vodu 1.7L ESP'], []],
            'a mini oven from another brand is outside the audited scope' => [$small + ['brand' => 'Other', 'name' => 'Mini pećnica 25L'], []],
            'a mini oven name outside the audited category is not enough' => [['brand' => 'Esper', 'name' => 'Mini pećnica 25L', 'category_name' => 'Alati'], []],
            'ordinary electric heaters are not automatically classified' => [['brand' => 'Midea', 'name' => 'Konvektorska grijalica 2000W MIDEA', 'category_name' => 'Električne grijalice'], []],
        ];
    }

    public function test_an_explicit_product_group_takes_precedence_over_every_inferred_scope(): void
    {
        $product = $this->product([
            'category_code' => 'herrera-oc-category-1025', 'category_name' => 'Električni bojleri',
            'name' => 'Bojler električni',
        ]);
        $product->update(['eprel_lookup_product_group' => 'spaceheaters']);

        $this->assertSame(['spaceheaters'], app(EprelCatalogProductMatcher::class)->criteria($product)['groups']);
        $this->assertFalse($product->relationLoaded('technicalSpecificationRows'));
        Http::assertNothingSent();
    }

    public function test_existing_lighting_root_and_descendants_still_use_the_original_scope(): void
    {
        $root = Category::create(['code' => 'herrera-oc-category-787', 'scope' => 'catalog', 'is_active' => true]);
        $child = Category::create(['code' => 'LIGHT-CHILD', 'scope' => 'catalog', 'is_active' => true, 'parent_id' => $root->id]);
        $product = $this->product();
        $product->categories()->sync([$child->id]);
        $product->unsetRelation('categories');

        $this->assertSame(['lightsources'], app(EprelCatalogProductMatcher::class)->criteria($product)['groups']);
        $this->assertFalse($product->relationLoaded('technicalSpecificationRows'));
        Http::assertNothingSent();
    }

    public function test_unrelated_tools_do_not_load_technical_specifications_or_product_names(): void
    {
        $product = $this->product(['category_name' => 'Alati', 'name' => 'Kliješta']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame([], app(EprelCatalogProductMatcher::class)->criteria($product)['groups']);
            $queries = array_column(DB::getQueryLog(), 'query');
            $this->assertFalse($product->relationLoaded('technicalSpecificationRows'));
            $this->assertFalse($product->relationLoaded('translations'));
            $this->assertSame([], array_values(array_filter($queries, fn ($query) => str_contains($query, 'catalog_product_specifications') || str_contains($query, '"product_translations"'))));
        } finally {
            DB::disableQueryLog();
        }
        Http::assertNothingSent();
    }

    private function product(array $case = []): Product
    {
        $brand = $case['brand'] ?? 'Braytron';
        $manufacturer = Manufacturer::create(['code' => 'CLASSIFICATION-BRAND', 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $brand, 'slug' => 'classification-brand']);
        $category = Category::create([
            'code' => $case['category_code'] ?? 'CLASSIFICATION-CATEGORY',
            'scope' => $case['scope'] ?? 'catalog', 'is_active' => true,
        ]);
        $category->translations()->create(['locale' => 'hr', 'name' => $case['category_name'] ?? 'Other', 'slug' => 'classification-category']);
        $product = Product::create([
            'code' => 'herrera-oc-product-999', 'sku' => 'LOCAL-SKU', 'barcode' => '5949097723490',
            'manufacturer_id' => $manufacturer->id, 'is_active' => true, 'energy_label_required' => false,
            'base_price' => '20.4800', 'stock_qty' => 3, 'supplier_stock_qty' => 9,
            'payload' => ['opencart' => ['model' => 'SOURCE-MODEL', 'ean' => '5949097723490']],
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => $case['name'] ?? 'Other product', 'slug' => 'classification-product']);
        $product->categories()->attach($category);
        if (isset($case['energy_values'])) {
            $product->technicalSpecificationRows()->create([
                'source' => $case['energy_source'] ?? 'herrera-opencart', 'source_key' => hash('sha256', 'classification-energy'),
                'group_name' => 'Specifikacije', 'item_name' => $case['energy_label'] ?? 'Razina energetske učinkovitosti',
                'values' => $case['energy_values'],
            ]);
        }

        return $product;
    }
}
