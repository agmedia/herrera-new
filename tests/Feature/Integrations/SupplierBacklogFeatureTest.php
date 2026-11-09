<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\SupplierCatalog\ImportSupplierCatalogueDrafts;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\SupplierCatalog\SupplierCatalogItem;
use App\Models\Settings\Local\TaxRate;
use App\Services\Import\LegacyOrderQuantityBackfill;
use App\Services\Integrations\Stock\FixedSupplierAvailabilityService;
use App\Services\Integrations\Stock\StockSyncService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Services\Integrations\SupplierCatalog\EcbPlnExchangeRate;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogFeedClient;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogRetirementService;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogService;
use App\Services\Integrations\SupplierCatalog\SupplierProcurementPriceImport;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SupplierBacklogFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_new_supplier_formats_preserve_identity_and_never_invent_missing_prices(): void
    {
        $client = app(SupplierCatalogFeedClient::class);
        $master = $client->parse('<products><product><code>VIP-1</code><ean>001</ean><name>VIP lamp</name><stock>12</stock></product></products>', 'master')[0];
        $this->assertSame('VIP-1', $master['sku']);
        $this->assertNull($master['source_price']);
        $vayox = $client->parse('<products><product><sku>V-1</sku><ean>002</ean><name>Vayox lamp</name><quantity>-1</quantity><image>https://images.example.test/v.jpg</image><description>Original</description></product></products>', 'vayox')[0];
        $this->assertSame(0, $vayox['quantity']);
        $this->assertNull($vayox['source_price']);
        $this->assertSame(['https://images.example.test/v.jpg'], $vayox['images']);
        $esperanza = $client->parse($this->esperanza(), 'esperanza')[0];
        $this->assertSame('EN name', $esperanza['name']);
        $this->assertSame('PLN', $esperanza['currency']);
        $this->assertSame('40', $esperanza['source_price']);
    }

    public function test_ecb_pln_inverse_and_default_factor_create_only_reviewable_drafts(): void
    {
        $options = $this->catalogOptions('esperanza');
        Http::fake([EcbPlnExchangeRate::SOURCE => Http::response($this->ecb())]);
        $item = SupplierCatalogItem::query()->create(['supplier' => 'esperanza', ...app(SupplierCatalogFeedClient::class)->parse($this->esperanza(), 'esperanza')[0]]);
        $service = app(SupplierCatalogService::class);
        $options = $service->validateOptions($options);
        $this->assertSame(0.25, $options['exchange_rate']);
        $this->assertSame(now()->toDateString(), $options['exchange_rate_date']);
        $service->import('esperanza', [$item->id], $options, null);
        $product = Product::query()->sole();
        $this->assertEquals(43.75, $product->base_price);
        $this->assertFalse($product->is_active);
        $this->assertSame('ecb', data_get($product->payload, 'supplier_catalog.exchange_rate_source'));
        $this->assertSame(now()->toDateString(), data_get($product->payload, 'supplier_catalog.exchange_rate_date'));
        $this->assertSame(now()->toDateString(), app(SystemSettingsService::class)->get('supplier_catalog_ecb_pln_reference')['date']);
        Http::assertSentCount(1);
    }

    public function test_current_esperanza_eur_feed_uses_no_exchange_and_accepts_decimal_zero(): void
    {
        $this->catalogOptions('master');
        $xml = str_replace(['<Waluta>PLN</Waluta>', '<StanJM>5</StanJM>'], ['<Waluta>EUR</Waluta>', '<StanJM>.00</StanJM>'], $this->esperanza());
        $row = app(SupplierCatalogFeedClient::class)->parse($xml, 'esperanza')[0];
        $item = SupplierCatalogItem::query()->create(['supplier' => 'esperanza', ...$row]);
        $service = app(SupplierCatalogService::class);
        $options = $service->options('esperanza');
        $this->assertSame('EUR', $options['source_currency']);
        $this->assertSame('manual', $options['exchange_rate_source']);
        $this->assertSame(1, $options['exchange_rate']);
        $this->assertSame(0, $row['quantity']);
        $decimal = app(SupplierCatalogFeedClient::class)->parse(str_replace('<CenaNettoJM>40</CenaNettoJM>', '<CenaNettoJM>.25</CenaNettoJM>', $xml), 'esperanza')[0];
        $this->assertSame('0.25', $decimal['source_price']);
        $this->assertEquals(175, $service->price($item, $service->validateOptions($options))); // 40 EUR × 3.5, normalized gross storage
        Http::assertNothingSent();
    }

    public function test_ecb_transport_failure_can_reuse_a_dated_recent_official_reference(): void
    {
        $this->catalogOptions('master');
        Http::fake([EcbPlnExchangeRate::SOURCE => Http::response($this->ecb())]);
        $first = app(EcbPlnExchangeRate::class)->latest();
        Cache::forget('supplier-catalog:ecb-pln');
        Http::fake([EcbPlnExchangeRate::SOURCE => Http::response('', 503)]);
        $fallback = app(EcbPlnExchangeRate::class)->latest();
        $this->assertSame($first['date'], $fallback['date']);
        $this->assertSame(0.25, $fallback['eur_per_pln']);
        $this->assertSame(EcbPlnExchangeRate::SOURCE, $fallback['source']);
    }

    public function test_ecb_unavailable_or_stale_blocks_import_without_changing_products(): void
    {
        $options = $this->catalogOptions('esperanza');
        Http::fake([EcbPlnExchangeRate::SOURCE => Http::response($this->ecb(now()->subDays(8)->toDateString()))]);
        $this->expectException(RuntimeException::class);
        try {
            app(SupplierCatalogService::class)->validateOptions($options);
        } finally {
            $this->assertDatabaseCount('products', 0);
        }
    }

    public function test_full_catalogue_job_skips_unpriced_unmapped_and_existing_rows_without_overwrite(): void
    {
        $options = $this->catalogOptions('master');
        $existing = Product::query()->create(['code' => 'original', 'sku' => 'EXISTING', 'base_price' => 99]);
        $eligible = $this->item('master', 'NEW', 10);
        $this->item('master', 'NO-PRICE', null);
        $this->item('master', 'EXISTING', 20);
        $job = new ImportSupplierCatalogueDrafts('master', $options, null, (int) SupplierCatalogItem::query()->max('id'));
        $job->handle(app(SupplierCatalogService::class));
        $this->assertDatabaseCount('products', 2);
        $this->assertFalse(Product::query()->findOrFail($eligible->fresh()->product_id)->is_active);
        $this->assertEquals(99, $existing->fresh()->base_price);
        $job->handle(app(SupplierCatalogService::class));
        $this->assertDatabaseCount('products', 2);
    }

    public function test_missing_feed_requires_explicit_review_and_keeps_own_stock_until_sold(): void
    {
        $product = Product::query()->create(['code' => 'retire', 'is_active' => true, 'stock_qty' => 3, 'supplier_stock_qty' => 20]);
        $item = $this->item('master', 'RETIRED', null);
        $item->update(['is_current' => false, 'product_id' => $product->id]);
        $this->assertTrue($product->fresh()->is_active);
        app(SupplierCatalogService::class)->reviewMissing('master', $item->id, null);
        $this->assertTrue($product->fresh()->is_active);
        $this->assertSame('after_own_stock_sold', $item->fresh()->review_action);
        $product = $product->fresh();
        $product->update(['stock_qty' => 0]);
        app(SupplierCatalogRetirementService::class)->reconcile($product);
        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(20, $product->fresh()->supplier_stock_qty);
        $this->assertFalse(data_get($product->fresh()->payload, 'supplier_catalog_retirement.pending'));
    }

    public function test_procurement_csv_preview_enrichment_and_refresh_preserve_reviewed_data(): void
    {
        $item = $this->item('master', 'VIP-1', null);
        $file = $this->csv("sku,source_price,currency\nVIP-1,10.25,EUR\n");
        try {
            $service = app(SupplierProcurementPriceImport::class);
            $this->assertSame(1, $service->run('master', $file)['updated']);
            $this->assertNull($item->fresh()->manual_source_price);
            $service->run('master', $file, true);
            $this->assertEquals(10.25, $item->fresh()->manual_source_price);
            app(StockSyncSettingsService::class)->saveConnection('master', ['url' => 'https://feeds.example.test/master']);
            Http::fake(['feeds.example.test/*' => Http::response('<products><product><code>VIP-1</code><name>Changed supplier name</name><stock>1</stock></product></products>')]);
            app(SupplierCatalogService::class)->refresh('master');
            $this->assertEquals(10.25, $item->fresh()->manual_source_price);
        } finally {
            unlink($file);
        }
    }

    public function test_legacy_quantity_backfill_preview_apply_and_manual_preservation(): void
    {
        $fix = Product::query()->create(['code' => 'pack', 'minimum_order_quantity' => 4, 'order_quantity_step' => 1, 'payload' => ['opencart' => ['minimum' => 4]]]);
        $manual = Product::query()->create(['code' => 'manual', 'minimum_order_quantity' => 3, 'order_quantity_step' => 3, 'payload' => ['opencart' => ['minimum' => 4]]]);
        $native = Product::query()->create(['code' => 'native', 'minimum_order_quantity' => 1, 'order_quantity_step' => 1]);
        $service = app(LegacyOrderQuantityBackfill::class);
        $this->assertSame(1, $service->run()['changed']);
        $this->assertSame(1, $fix->fresh()->order_quantity_step);
        $result = $service->run(true);
        $this->assertSame(1, $result['manual_preserved']);
        $this->assertSame(4, $fix->fresh()->minimum_order_quantity);
        $this->assertSame(4, $fix->fresh()->order_quantity_step);
        $this->assertSame(3, $manual->fresh()->minimum_order_quantity);
        $this->assertSame(1, $native->fresh()->minimum_order_quantity);
        $this->assertSame(0, $service->run(true)['changed']);
    }

    public function test_fixed_supplier_availability_is_explicit_and_survives_xml_and_manual_snapshot(): void
    {
        $brand = Manufacturer::query()->create(['code' => 'nopallux']);
        $product = Product::query()->create(['code' => 'fixed', 'sku' => 'FIX-1', 'manufacturer_id' => $brand->id, 'stock_qty' => 7, 'supplier_stock_qty' => 5]);
        $untouched = Product::query()->create(['code' => 'other', 'supplier_stock_qty' => 3]);
        $service = app(FixedSupplierAvailabilityService::class);
        $this->assertSame(1, $service->run([$brand->id])['products']);
        $this->assertSame(5, $product->fresh()->supplier_stock_qty);
        $service->run([$brand->id], true);
        $this->assertSame(100000, $product->fresh()->supplier_stock_qty);
        $this->assertSame(7, $product->fresh()->stock_qty);
        $this->assertSame(3, $untouched->fresh()->supplier_stock_qty);
        app(StockSyncSettingsService::class)->saveConnection('master', ['url' => 'https://feeds.example.test/master']);
        Http::fake(['feeds.example.test/*' => Http::response('<products><product><code>FIX-1</code><stock>0</stock></product></products>')]);
        $this->assertSame('completed', app(StockSyncService::class)->run('master', 'manual')->status);
        $this->assertSame(100000, $product->fresh()->supplier_stock_qty);
        $file = $this->csv("sku,quantity\nFIX-1,9\n");
        try {
            $service->importCsv($file, true);
            $this->assertSame(9, $product->fresh()->supplier_stock_qty);
            $this->assertSame('fixed', data_get($product->fresh()->payload, 'supplier_availability.mode'));
            $this->assertSame(7, $product->fresh()->stock_qty);
        } finally {
            unlink($file);
        }
    }

    public function test_fixed_supplier_policy_rejects_synchronized_supplier_selection(): void
    {
        $brand = Manufacturer::query()->create(['code' => 'videx']);
        $this->expectException(RuntimeException::class);
        app(FixedSupplierAvailabilityService::class)->run([$brand->id], true);
    }

    public function test_fixed_supplier_policy_rejects_combined_legacy_xml_supplier_names(): void
    {
        foreach (['Master - VIP', 'Vipelectro / Master / VIP', 'Videx / Allegro', 'Allegro - Videx'] as $index => $name) {
            $brand = Manufacturer::query()->create(['code' => 'herrera-oc-manufacturer-'.$index]);
            $brand->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => 'supplier-'.$index]);
            $product = Product::query()->create(['code' => 'xml-'.$index, 'manufacturer_id' => $brand->id, 'supplier_stock_qty' => 7]);
            try {
                app(FixedSupplierAvailabilityService::class)->run([$brand->id], true);
                $this->fail('Combined XML supplier label must be rejected.');
            } catch (RuntimeException) {
                $this->assertSame(7, $product->fresh()->supplier_stock_qty);
                $this->assertNull(data_get($brand->fresh()->payload, 'supplier_availability'));
            }
        }
    }

    private function catalogOptions(string $supplier): array
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        $tax = TaxRate::query()->create(['code' => 'vat25', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_active' => true, 'is_default' => true]);
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'review']);

        return array_replace(app(SupplierCatalogService::class)->options($supplier), ['default_category_id' => $category->id, 'tax_rate_id' => $tax->id], $supplier === 'esperanza' ? ['source_currency' => 'PLN', 'exchange_rate_source' => 'ecb', 'exchange_rate' => null] : []);
    }

    private function item(string $supplier, string $sku, ?float $price): SupplierCatalogItem
    {
        return SupplierCatalogItem::query()->create(['supplier' => $supplier, 'external_id' => $sku, 'sku' => $sku, 'name' => $sku, 'source_price' => $price, 'currency' => $price !== null ? 'EUR' : null, 'images' => [], 'specifications' => [], 'quantity' => 3]);
    }

    private function esperanza(): string
    {
        return '<ListaStanCena><Produkty><Produkt><Symbol>E-1</Symbol><EAN>003</EAN><Waluta>PLN</Waluta><CenaNettoJM>40</CenaNettoJM><StanJM>5</StanJM><Nazwa>PL name</Nazwa><NazwaEN>EN name</NazwaEN></Produkt></Produkty></ListaStanCena>';
    }

    private function ecb(?string $date = null): string
    {
        return '<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="'.($date ?? now()->toDateString()).'"><Cube currency="PLN" rate="4.0000"/></Cube></Cube></gesmes:Envelope>';
    }

    private function csv(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'supplier-test-');
        file_put_contents($file, $content);

        return $file;
    }
}
