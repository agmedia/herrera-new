<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Services\Import\TermolProductSnapshotImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermolProductSnapshotImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_general_termol_products_with_source_metadata_and_manufacturer(): void
    {
        User::factory()->create();
        TaxRate::query()->create([
            'code' => 'pdv25',
            'name' => 'PDV 25%',
            'geo_zone_id' => null,
            'rate_type' => 'percent',
            'rate' => 25,
            'priority' => 1,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
            'settings' => null,
        ]);

        $sourceCategoryPath = '/klimatizacija/aux-1/setovi-19.aspx';
        $category = new Category([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'termol-'.substr(hash('sha256', $sourceCategoryPath), 0, 24),
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 10,
            'payload' => [
                'source' => 'termol.hr',
                'source_url' => 'https://www.termol.hr'.$sourceCategoryPath,
            ],
        ]);
        $category->saveAsRoot();
        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => 'Setovi',
            'slug' => 'setovi-19',
        ]);

        $snapshot = tempnam(sys_get_temp_dir(), 'termol-product-snapshot-');
        $this->assertNotFalse($snapshot);
        file_put_contents($snapshot, json_encode([[
            'name' => 'AUX KLIMA TEST 3,5 KW',
            'sku' => 'TERMOL-TEST-1',
            'price' => '634,00 €',
            'stock' => 'Na stanju',
            'source_url' => 'https://www.termol.hr/aux-klima-test.aspx',
            'category_path' => $sourceCategoryPath,
            'description_html' => '<p>Opis testnog klima uređaja.</p>',
            'source_image_url' => 'https://www.termol.hr/test-image.jpg',
            'local_image_path' => '',
            'manufacturer' => 'AUX',
            'main_category' => 'Klimatizacija',
            'main_category_path' => '/klimatizacija.aspx',
            'installment_pricing' => 'cijena za 2 do 12 rata - 700,00 €',
            'breadcrumbs' => [
                ['name' => 'Klimatizacija', 'path' => '/klimatizacija.aspx'],
                ['name' => 'Setovi', 'path' => $sourceCategoryPath],
            ],
            'specifications' => [
                ['group' => 'Tehničke specifikacije', 'label' => 'Snaga', 'value' => '3,5 kW'],
            ],
            'documents' => [
                ['name' => 'Energetska oznaka', 'url' => 'https://www.termol.hr/test.pdf'],
            ],
            'images' => [
                ['source_url' => 'https://www.termol.hr/test-image.jpg', 'alt' => 'AUX KLIMA TEST'],
            ],
        ]], JSON_THROW_ON_ERROR));

        try {
            $stats = app(TermolProductSnapshotImportService::class)->import($snapshot, false);
        } finally {
            @unlink($snapshot);
        }

        $this->assertSame(1, $stats['products_imported']);
        $this->assertSame(1, $stats['categories_linked']);
        $this->assertSame(1, $stats['manufacturers_linked']);
        $this->assertSame(1, $stats['images_skipped']);

        $product = Product::query()
            ->with(['translations', 'categories', 'manufacturer.translations'])
            ->where('sku', 'TERMOL-TEST-1')
            ->firstOrFail();

        $this->assertSame('634.00', $product->base_price);
        $this->assertSame(1, $product->stock_qty);
        $this->assertSame($category->id, $product->categories->first()?->id);
        $this->assertSame('AUX', $product->manufacturer?->translations->firstWhere('locale', 'hr')?->name);
        $this->assertSame(1, Manufacturer::query()->where('code', 'aux')->count());
        $this->assertSame('Klimatizacija', data_get($product->payload, 'source_main_category'));
        $this->assertSame(
            'https://www.termol.hr/test.pdf',
            data_get($product->payload, 'source_documents.0.url')
        );
        $this->assertNull(data_get($product->payload, 'source_documents.0.local_path'));

        $description = (string) $product->translations->firstWhere('locale', 'hr')?->description;
        $this->assertStringContainsString('Opis testnog klima uređaja.', $description);
        $this->assertStringContainsString('<h2>Tehničke specifikacije</h2>', $description);
        $this->assertStringContainsString('<dt>Snaga</dt><dd>3,5 kW</dd>', $description);
    }
}
