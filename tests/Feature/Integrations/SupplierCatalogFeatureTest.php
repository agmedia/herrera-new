<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\SupplierCatalog\ImportSupplierImages;
use App\Livewire\Admin\Integrations\SupplierCatalog\Dashboard;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\SupplierCatalog\SupplierCatalogItem;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogFeedClient;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class SupplierCatalogFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_parses_all_three_supplier_formats_and_dpm_photo_attributes(): void
    {
        $client = app(SupplierCatalogFeedClient::class);
        $videx = $client->parse('<yml_catalog><shop><categories><category id="12">Rasvjeta</category></categories><offers><offer id="1"><vendorCode>001</vendorCode><name>Lampa</name><prices><price currencyId="EUR">10.00</price></prices><stock_quantity>9</stock_quantity><categoryId>12</categoryId><picture>https://images.example.test/1.jpg</picture><param name="EAN">000123</param><param name="Snaga">5 W</param></offer></offers></shop></yml_catalog>', 'videx')[0];
        $this->assertSame('001', $videx['sku']);
        $this->assertSame('000123', $videx['barcode']);
        $this->assertSame('EUR', $videx['currency']);
        $this->assertSame('Rasvjeta', $videx['category_name']);
        $this->assertCount(2, $videx['specifications']);
        $dpm = $client->parse($this->dpmXml(), 'dpm')[0];
        $this->assertSame('D-1', $dpm['sku']);
        $this->assertSame(10, $dpm['quantity']);
        $this->assertSame(['https://images.example.test/main.jpg', 'https://images.example.test/extra.jpg'], $dpm['images']);
        $braytron = $client->parse('<Stoklar><Stok><ProductCode>B-1</ProductCode><ProductName>Žarulja</ProductName><Quantity>5</Quantity><NetPrice>0,3811</NetPrice><ListPrice>1,03</ListPrice><Image2>https://images.example.test/b.jpg</Image2></Stok></Stoklar>', 'braytron')[0];
        $this->assertSame('0.3811', $braytron['source_price']);
        $this->assertSame('Žarulja', $braytron['name']);
    }

    public function test_refresh_import_mapping_markup_tax_and_duplicate_recheck(): void
    {
        $this->configure('dpm');
        Http::fake(['feeds.example.test/*' => Http::response($this->dpmXml())]);
        $service = app(SupplierCatalogService::class);
        $this->assertSame(1, $service->refresh('dpm'));
        $item = SupplierCatalogItem::query()->firstOrFail();
        $options = $this->importOptions();
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'mapped']);
        $options['category_map'][hash('sha256', 'uncategorized')] = $category->id;
        $options['markup'] = 20;
        $actor = User::factory()->create();
        $result = $service->import('dpm', [$item->id], $options, $actor->id);
        $this->assertSame(1, $result['created']);
        $product = Product::query()->findOrFail($item->fresh()->product_id);
        $this->assertFalse($product->is_active);
        $this->assertEquals(15, $product->base_price); // 10 net + 20% markup + 25% tax
        $this->assertSame(10, $product->supplier_stock_qty);
        $this->assertSame(0, $product->stock_qty);
        $this->assertSame($category->id, $product->categories()->first()->id);
        $this->assertSame('Nosač', $product->translations()->first()->name);
        Queue::assertPushed(ImportSupplierImages::class);
        $this->assertSame(1, $service->import('dpm', [$item->id], $options, $actor->id)['skipped']);
        $this->assertSame(1, Product::query()->count());
    }

    public function test_import_rechecks_legacy_ean_and_sku_and_does_not_overwrite_existing_products(): void
    {
        $this->configure('dpm');
        Http::fake(['feeds.example.test/*' => Http::response($this->dpmXml())]);
        $service = app(SupplierCatalogService::class);
        $service->refresh('dpm');
        $item = SupplierCatalogItem::query()->firstOrFail();
        Product::query()->create(['code' => 'old', 'sku' => 'changed', 'base_price' => 80, 'payload' => ['opencart' => ['ean' => '000123']]]);
        $result = $service->import('dpm', [$item->id], $this->importOptions(), null);
        $this->assertSame(1, $result['skipped']);
        $this->assertEquals(80, Product::query()->first()->base_price);
        Queue::assertNothingPushed();
    }

    public function test_failed_refresh_preserves_snapshot_and_success_marks_missing_items_stale(): void
    {
        $this->configure('dpm');
        Http::fake(['feeds.example.test/*' => Http::sequence()->push($this->dpmXml())->push('broken')->push(str_replace('D-1', 'D-2', $this->dpmXml()))]);
        $service = app(SupplierCatalogService::class);
        $service->refresh('dpm');
        try {
            $service->refresh('dpm');
            $this->fail('Invalid feed must fail.');
        } catch (RuntimeException) {
            $this->assertTrue(SupplierCatalogItem::query()->first()->is_current);
        }
        $service->refresh('dpm');
        $this->assertFalse(SupplierCatalogItem::query()->where('sku', 'D-1')->first()->is_current);
        $this->assertTrue(SupplierCatalogItem::query()->where('sku', 'D-2')->first()->is_current);
    }

    public function test_missing_category_invalid_price_and_wrong_supplier_cannot_import(): void
    {
        $this->configure('dpm');
        Http::fake(['feeds.example.test/*' => Http::response($this->dpmXml())]);
        $service = app(SupplierCatalogService::class);
        $service->refresh('dpm');
        $item = SupplierCatalogItem::query()->first();
        $options = $this->importOptions();
        $options['default_category_id'] = null;
        foreach ([['dpm', $options], ['videx', $this->importOptions()]] as [$supplier, $settings]) {
            try {
                $service->import($supplier, [$item->id], $settings, null);
                $this->fail('Invalid import must fail.');
            } catch (RuntimeException) {
                $this->assertSame(0, Product::query()->count());
            }
        }
        $item->update(['source_price' => null]);
        $this->expectException(RuntimeException::class);
        $service->import('dpm', [$item->id], $this->importOptions(), null);
    }

    public function test_settings_are_independent_and_currency_mismatch_blocks_price(): void
    {
        $service = app(SupplierCatalogService::class);
        $options = $this->importOptions();
        $options['markup'] = 35;
        $service->saveOptions('videx', $options);
        $this->assertEquals(35, $service->options('videx')['markup']);
        $this->assertEquals(0, $service->options('dpm')['markup']);
        $this->assertNull($service->price(new SupplierCatalogItem(['source_price' => 10, 'currency' => 'USD']), $options));
    }

    public function test_dashboard_navigation_actions_and_permissions(): void
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'supplier-admin']);
        Bouncer::assign('supplier-admin')->to($user);
        Bouncer::allow($user)->to('admin.access');
        $this->actingAs($user)->get(route('admin.integrations.supplier-catalog.index'))->assertForbidden();
        Livewire::test(Dashboard::class)->assertForbidden();
        Bouncer::allow($user)->to('integrations.stock.manage');
        Bouncer::refresh();
        $this->importOptions();
        $this->get(route('admin.integrations.supplier-catalog.index'))->assertOk()->assertSee('Artikli dobavljača')->assertSee('Videx')->assertSee('Braytron');
        $this->configure('dpm');
        Http::fake(['feeds.example.test/*' => Http::response($this->dpmXml())]);
        Livewire::test(Dashboard::class)->call('chooseSupplier', 'dpm')->call('refreshFeed')->assertSet('failed', false)->assertSee('Nosač')
            ->set('options.markup', 15)->call('saveSettings')->assertHasNoErrors()->call('selectPage')->assertSet('selected', [strval(SupplierCatalogItem::query()->first()->id)]);
    }

    private function configure(string $supplier): void
    {
        app(StockSyncSettingsService::class)->saveConnection($supplier, ['url' => 'https://feeds.example.test/'.$supplier]);
    }

    private function importOptions(): array
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        $tax = TaxRate::query()->firstOrCreate(['code' => 'vat25'], ['name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_active' => true, 'is_default' => true]);
        $category = Category::query()->firstOrCreate(['code' => 'default'], ['scope' => 'catalog']);

        return ['markup' => 0, 'default_category_id' => $category->id, 'category_map' => [], 'source_currency' => 'EUR', 'exchange_rate' => 1, 'source_includes_tax' => false, 'tax_rate_id' => $tax->id];
    }

    private function dpmXml(): string
    {
        return '<offers><PRODUCT code="D-1"><name_hr>Nosač</name_hr><desc_hr>Opis</desc_hr><price_EUR>10.00</price_EUR><ean>000123</ean><stock>100</stock><BOX_QTY>10</BOX_QTY><photo><main url="https://images.example.test/main.jpg"/><i url="https://images.example.test/extra.jpg"/></photo></PRODUCT></offers>';
    }
}
