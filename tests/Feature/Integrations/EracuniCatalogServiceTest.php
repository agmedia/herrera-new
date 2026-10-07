<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\CatalogProductSpecification;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Eracuni\EracuniCatalogRun;
use App\Models\Settings\Local\TaxRate;
use App\Services\Integrations\Eracuni\EracuniCatalogService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EracuniCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app(StockSyncSettingsService::class)->saveConnection('eracuni', [
            'url' => 'https://erp.example.test/api/WarehouseGetArticleStockQuantity',
            'username' => 'erp-user', 'token' => 'erp-private-token', 'password' => 'erp-private-password',
        ]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_preview_uses_product_list_auth_and_writes_no_catalog_data(): void
    {
        $this->feed([$this->row()]);

        $run = $this->service()->preview();

        $this->assertSame('completed', $run->status);
        $this->assertSame('preview', $run->kind);
        $this->assertSame(1, $run->eligible_count);
        $this->assertSame('new', $run->items()->first()->status);
        $this->assertSame('00123', $run->items()->first()->plan['model']);
        $this->assertSame('review', $run->items()->first()->plan['price_state']);
        $this->assertSame('125.0000', $run->items()->first()->plan['gross_price']);
        $this->assertSame([['label' => 'Snaga', 'value' => '10 W'], ['label' => 'Boja', 'value' => 'Bijela']], $run->items()->first()->plan['attributes']);
        $this->assertArrayNotHasKey('source_payload', $run->items()->first()->toArray());
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('catalog_attributes', 0);
        $this->assertDatabaseCount('catalog_product_specifications', 0);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://erp.example.test/api/ProductList'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('erp-user:erp-private-token_erp-private-password'))
            && $request->hasHeader('Accept-Encoding', 'identity')
            && $request->body() === '');
    }

    public function test_product_list_accepts_actual_legacy_broken_content_encoding_header(): void
    {
        Http::fake(['erp.example.test/*' => Http::response(json_encode(['response' => ['result' => [$this->row()]]]), 200,
            ['Content-Type' => 'application/json', 'Content-Encoding' => '8-bit'])]);

        $run = $this->service()->preview();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->eligible_count);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Accept-Encoding', 'identity'));
    }

    public function test_only_selected_candidates_become_inactive_drafts_and_reimport_is_safe(): void
    {
        $tax = $this->tax();
        $this->feed([$this->row(), $this->row(['productCode' => 'NEXT', 'barCode' => '456'])]);
        $preview = $this->service()->preview();
        $candidate = $preview->items()->where('identifier', '00123')->first();

        $run = $this->service()->import($preview->id, [$candidate->id]);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->created_count);
        $this->assertSame($preview->id, $run->summary['preview_run_id']);
        $this->assertDatabaseCount('products', 1);
        $product = Product::query()->firstOrFail();
        $this->assertSame('00123', $product->sku);
        $this->assertFalse($product->is_active);
        $this->assertSame(0, $product->stock_qty);
        $this->assertSame(0, $product->supplier_stock_qty);
        $this->assertEquals(100, $product->base_price);
        $this->assertSame($tax->id, $product->tax_rate_id);
        $this->assertSame('00123', $product->payload['eracuni']['productCode']);
        $this->assertArrayNotHasKey('opencart', $product->payload);
        $this->assertFalse($product->payload['eracuni']['price_review_required']);
        $this->assertSame('created', $candidate->fresh()->status);
        $this->assertCount(2, $product->attributes);
        $this->assertCount(2, $product->technicalSpecificationRows);
        $this->assertDatabaseCount('category_product', 0);
        $this->assertSame('new', $preview->items()->where('identifier', 'NEXT')->first()->status);

        $again = $this->service()->import($preview->id, [$candidate->id]);
        $this->assertSame('completed', $again->status);
        $this->assertSame(0, $again->created_count);
        $this->assertSame(1, $again->skipped_count);
        $this->assertDatabaseCount('products', 1);
        Http::assertSentCount(1);
    }

    public function test_unconfigured_tax_keeps_draft_price_for_review_without_invented_tax(): void
    {
        $this->feed([$this->row()]);
        $preview = $this->service()->preview();
        $run = $this->service()->import($preview->id, [$preview->items()->first()->id]);

        $this->assertSame('completed', $run->status);
        $product = Product::query()->firstOrFail();
        $this->assertEquals(0, $product->base_price);
        $this->assertNull($product->tax_rate_id);
        $this->assertTrue($product->payload['eracuni']['price_review_required']);
        $this->assertSame('125.0000', $product->payload['eracuni']['grossPrice']);
    }

    public function test_existing_legacy_identity_and_hidden_erp_products_are_not_import_candidates(): void
    {
        $product = Product::query()->create(['code' => 'legacy', 'sku' => 'ADJUSTED-SKU', 'payload' => ['opencart' => ['sku' => '00123', 'model' => 'ERP-MODEL']], 'base_price' => 19]);
        $this->feed([$this->row(), $this->row(['productCode' => 'HIDDEN', 'onlineShopVisibility' => 'hiddenOnline', 'barCode' => '456'])]);

        $run = $this->service()->preview();

        $this->assertSame(0, $run->eligible_count);
        $this->assertSame('existing', $run->items()->where('identifier', '00123')->first()->status);
        $this->assertSame($product->id, $run->items()->where('identifier', '00123')->first()->product_id);
        $this->assertSame('skipped', $run->items()->where('identifier', 'HIDDEN')->first()->status);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_sync_attributes_preserves_manual_attributes_and_all_product_fields_and_is_idempotent(): void
    {
        $product = Product::query()->create([
            'code' => 'legacy', 'sku' => 'ADJUSTED', 'barcode' => '123', 'base_price' => 37, 'stock_qty' => 8,
            'supplier_stock_qty' => 12, 'is_active' => true, 'payload' => ['opencart' => ['sku' => '00123', 'model' => 'ERP-MODEL']],
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Ručno uređen naziv', 'slug' => 'manual', 'description' => 'Ručno uređen opis']);
        $manual = Attribute::query()->create(['code' => 'manual-value', 'group_code' => 'manual-group', 'payload' => ['source' => 'manual']]);
        $product->attributes()->attach($manual->id);
        $product->technicalSpecificationRows()->create(['source' => 'manual', 'source_key' => str_repeat('1', 64), 'group_name' => 'Ručno', 'item_name' => 'Ručno', 'values' => ['Sačuvano']]);
        $before = $product->fresh()->getAttributes();
        $this->feed([$this->row()]);

        $run = $this->service()->syncAttributes();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->updated_count);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame('Ručno uređen opis', $product->translations()->first()->description);
        $this->assertTrue($product->attributes()->whereKey($manual->id)->exists());
        $this->assertSame(3, $product->attributes()->count());
        $this->assertSame(3, $product->technicalSpecificationRows()->count());
        $again = $this->service()->syncAttributes();
        $this->assertSame(0, $again->updated_count);
        $this->assertSame(1, $again->unchanged_count);

        $this->feed([$this->row(['description' => 'Boja: Crna'])]);
        $changed = $this->service()->syncAttributes();
        $this->assertSame(1, $changed->updated_count);
        $this->assertTrue($product->attributes()->whereKey($manual->id)->exists());
        $this->assertSame(2, $product->attributes()->count());
        $this->assertSame(1, $product->technicalSpecificationRows()->where('source', 'eracuni')->count());
        $this->assertSame(['Crna'], $product->technicalSpecificationRows()->where('source', 'eracuni')->first()->values);
    }

    public function test_ambiguous_legacy_model_never_updates_either_product(): void
    {
        foreach (['ONE', 'TWO'] as $code) {
            Product::query()->create(['code' => $code, 'sku' => $code, 'payload' => ['opencart' => ['model' => '00123']]]);
        }
        $this->feed([$this->row()]);

        $run = $this->service()->syncAttributes();

        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->updated_count);
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame('ambiguous', $run->items()->first()->status);
        $this->assertDatabaseCount('catalog_attribute_product', 0);
    }

    public function test_invalid_later_record_rolls_back_without_partial_attribute_updates(): void
    {
        Product::query()->create(['code' => 'legacy', 'sku' => '00123']);
        $this->feed([$this->row(), $this->row(['productCode' => '', 'description' => 'Snaga: 10 W'."\n".'Snaga: 99 W'])]);

        $run = $this->service()->syncAttributes();

        $this->assertSame('failed', $run->status);
        $this->assertDatabaseCount('catalog_attribute_product', 0);
        $this->assertDatabaseCount('catalog_attributes', 0);
        $this->assertDatabaseCount('catalog_product_specifications', 0);
        $this->assertSame(0, $run->items()->count());
        $this->assertStringNotContainsString('erp-private', $run->error_message);
    }

    public function test_product_creation_and_report_items_roll_back_when_specification_write_fails(): void
    {
        $this->feed([$this->row()]);
        $preview = $this->service()->preview();
        Event::listen('eloquent.created: '.CatalogProductSpecification::class, fn () => throw new RuntimeException('private-database-detail'));

        $run = $this->service()->import($preview->id, [$preview->items()->first()->id]);

        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->created_count);
        $this->assertSame(0, $run->items()->count());
        $this->assertSame('new', $preview->items()->first()->status);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('catalog_attributes', 0);
        $this->assertDatabaseCount('catalog_product_specifications', 0);
        $this->assertStringNotContainsString('private-database-detail', $run->error_message);
    }

    public function test_partial_erp_response_and_private_transport_details_are_safely_failed(): void
    {
        Http::fake(['erp.example.test/*' => Http::response(['response' => ['result' => [$this->row()], 'complete' => false]])]);
        $run = $this->service()->preview();
        $this->assertSame('failed', $run->status);
        $this->assertDatabaseCount('eracuni_catalog_items', 0);
        $this->assertDatabaseCount('products', 0);

        Http::fake(fn () => throw new RuntimeException('https://erp.example.test/private-key'));
        $failed = $this->service()->preview();
        $this->assertSame('failed', $failed->status);
        $this->assertStringNotContainsString('private-key', $failed->error_message);
        $this->assertStringNotContainsString('https://', $failed->error_message);
    }

    public function test_selection_cannot_cross_previews_and_price_changes_require_a_fresh_preview(): void
    {
        $tax = $this->tax();
        $this->feed([$this->row()]);
        $first = $this->service()->preview();
        $second = $this->service()->preview();
        $wrong = $this->service()->import($first->id, [$second->items()->first()->id]);
        $this->assertSame('failed', $wrong->status);
        $this->assertDatabaseCount('products', 0);

        $tax->update(['rate' => 13]);
        $stale = $this->service()->import($first->id, [$first->items()->first()->id]);
        $this->assertSame('failed', $stale->status);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_concurrent_catalog_processing_is_rejected_before_fetch_or_report(): void
    {
        $lock = Cache::lock('herrera-eracuni-catalog', 900);
        $this->assertTrue($lock->get());
        try {
            $this->expectException(RuntimeException::class);
            $this->service()->preview();
        } finally {
            $lock->release();
            Http::assertNothingSent();
            $this->assertSame(0, EracuniCatalogRun::query()->count());
        }
    }

    public function test_price_preview_does_not_write_and_selected_apply_only_changes_reviewed_price(): void
    {
        $tax = $this->tax();
        $product = Product::query()->create(['code' => '00123', 'sku' => '00123', 'tax_rate_id' => $tax->id,
            'base_price' => 75, 'stock_qty' => 8, 'supplier_stock_qty' => 10, 'payload' => ['manual' => true]]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Manual name', 'slug' => 'manual', 'description' => 'Manual content']);
        $this->feed([$this->row()]);

        $preview = $this->service()->previewUpdates('prices');

        $this->assertSame('preview_prices', $preview->kind);
        $this->assertSame(1, $preview->eligible_count);
        $candidate = $preview->items()->first();
        $this->assertSame('update', $candidate->status);
        $this->assertSame('75.0000', $candidate->plan['old_base_price']);
        $this->assertSame('100.0000', $candidate->plan['base_price']);
        $this->assertEquals(75, $product->fresh()->base_price);

        $run = $this->service()->applyUpdates($preview->id, [$candidate->id]);

        $this->assertSame('completed', $run->status);
        $this->assertSame('prices', $run->kind);
        $this->assertSame(1, $run->updated_count);
        $this->assertEquals(100, $product->fresh()->base_price);
        $this->assertSame($tax->id, $product->fresh()->tax_rate_id);
        $this->assertSame(8, $product->fresh()->stock_qty);
        $this->assertSame(10, $product->fresh()->supplier_stock_qty);
        $this->assertSame(['manual' => true], $product->fresh()->payload);
        $this->assertSame('Manual name', $product->translations()->first()->name);
        $this->assertSame('Manual content', $product->translations()->first()->description);
        $this->assertSame('updated', $candidate->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_price_update_uses_assigned_tax_and_never_overwrites_a_price_edited_after_preview(): void
    {
        $this->tax();
        $productTax = TaxRate::query()->create(['code' => 'pdv13', 'name' => 'PDV 13%', 'rate' => 13, 'rate_type' => 'percent', 'is_active' => true]);
        $product = Product::query()->create(['code' => '00123', 'sku' => '00123', 'tax_rate_id' => $productTax->id, 'base_price' => 75]);
        $this->feed([$this->row(['grossPrice' => '113,00', 'vatPercentage' => '13'])]);
        $preview = $this->service()->previewUpdates('prices');
        $candidate = $preview->items()->first();
        $this->assertSame('100.0000', $candidate->plan['base_price']);
        $this->assertSame($productTax->id, $candidate->plan['tax_rate_id']);

        $product->update(['base_price' => 82]);
        $run = $this->service()->applyUpdates($preview->id, [$candidate->id]);

        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->updated_count);
        $this->assertSame(1, $run->skipped_count);
        $this->assertEquals(82, $product->fresh()->base_price);
    }

    public function test_name_update_preserves_description_slug_prices_and_stock_and_requires_selected_apply(): void
    {
        $product = Product::query()->create(['code' => '00123', 'sku' => '00123', 'base_price' => 75, 'stock_qty' => 8]);
        $translation = $product->translations()->create(['locale' => 'hr', 'name' => 'Old name', 'slug' => 'stable-url', 'description' => 'Manual description', 'meta_title' => 'Manual SEO']);
        $before = $product->fresh()->getAttributes();
        $this->feed([$this->row()]);

        $preview = $this->service()->previewUpdates('names');
        $candidate = $preview->items()->first();

        $this->assertSame('Old name', $translation->fresh()->name);
        $this->assertSame('Old name', $candidate->plan['old_name']);
        $run = $this->service()->applyUpdates($preview->id, [$candidate->id]);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->updated_count);
        $this->assertSame('ERP svjetiljka', $translation->fresh()->name);
        $this->assertSame('stable-url', $translation->fresh()->slug);
        $this->assertSame('Manual description', $translation->fresh()->description);
        $this->assertSame('Manual SEO', $translation->fresh()->meta_title);
        $this->assertSame($before, $product->fresh()->getAttributes());

        $again = $this->service()->applyUpdates($preview->id, [$candidate->id]);
        $this->assertSame(0, $again->updated_count);
        $this->assertSame(1, $again->skipped_count);
    }

    public function test_range_filters_follow_legacy_form_parameters_and_scope_is_reported(): void
    {
        $this->feed([$this->row()]);

        $run = $this->service()->preview(codeFrom: '10001', codeTo: '20000');

        $this->assertSame('completed', $run->status);
        $this->assertSame('range', $run->summary['scope']);
        $this->assertSame('10001', $run->summary['code_from']);
        $this->assertFalse($run->summary['limit_reached']);
        Http::assertSent(fn (Request $request): bool => $request->body() === 'productCodeFrom=%2210001%22&productCodeTo=%2220000%22');
    }

    public function test_capped_source_is_reported_and_a_capped_preview_cannot_be_applied(): void
    {
        $rows = [];
        for ($i = 0; $i < 10000; $i++) {
            $rows[] = $this->row(['productCode' => 'CAP-'.$i]);
        }
        $this->feed($rows);
        $client = app(\App\Services\Integrations\Eracuni\EracuniCatalogClient::class);
        $this->assertCount(10000, $client->products());
        $this->assertTrue($client->fetchMetadata()['limit_reached']);
        $this->assertNotEmpty($client->fetchMetadata()['warning']);

        $this->feed([$this->row()]);
        $preview = $this->service()->preview();
        $preview->update(['summary' => ['limit_reached' => true]]);
        $run = $this->service()->import($preview->id, [$preview->items()->first()->id]);
        $this->assertSame('failed', $run->status);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_currency_or_vat_mismatch_prevents_price_updates_and_marks_drafts_for_review(): void
    {
        $tax = $this->tax();
        $product = Product::query()->create(['code' => '00123', 'sku' => '00123', 'base_price' => 75, 'tax_rate_id' => $tax->id]);
        $this->feed([$this->row(['currency' => 'USD'])]);
        $currency = $this->service()->previewUpdates('prices');
        $this->assertSame(0, $currency->eligible_count);
        $this->assertSame('skipped', $currency->items()->first()->status);
        $this->assertEquals(75, $product->fresh()->base_price);

        $this->feed([$this->row(['vatPercentage' => '5'])]);
        $vat = $this->service()->previewUpdates('prices');
        $this->assertSame(0, $vat->eligible_count);
        $this->assertSame('review', $vat->items()->first()->plan['price_state']);
        $this->assertEquals(75, $product->fresh()->base_price);
    }

    public function test_unsupported_attributes_are_reported_per_product_while_other_valid_products_sync(): void
    {
        $valid = Product::query()->create(['code' => '00123', 'sku' => '00123']);
        $invalid = Product::query()->create(['code' => 'BAD', 'sku' => 'BAD']);
        $this->feed([$this->row(), $this->row(['productCode' => 'BAD', 'description' => "Snaga: 10 W\nSnaga: 99 W"])]);

        $run = $this->service()->syncAttributes();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->updated_count);
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame(2, $valid->attributes()->count());
        $this->assertSame(0, $invalid->attributes()->count());
        $this->assertSame('invalid', $run->items()->where('identifier', 'BAD')->first()->status);
    }

    public function test_ideus_csv_preview_preserves_identifiers_and_raw_price_then_imports_selected_inactive_draft_without_erp_marker(): void
    {
        $this->tax();
        $manufacturer = \App\Models\Catalog\Manufacturer\Manufacturer::query()->create(['code' => 'braytron', 'is_active' => true]);
        $path = $this->csvFile([$this->csvRow(), $this->csvRow([0 => 'NEXT', 8 => '456'])]);

        $preview = $this->service()->previewSupplierCsv($path);

        $this->assertSame('completed', $preview->status);
        $this->assertSame('preview_csv', $preview->kind);
        $this->assertSame(2, $preview->eligible_count);
        $this->assertFalse($preview->summary['limit_reached']);
        $this->assertSame(basename($path), $preview->summary['file_name']);
        $this->assertArrayNotHasKey('path', $preview->summary);
        $candidate = $preview->items()->where('identifier', '00123')->firstOrFail();
        $this->assertSame('00123', $candidate->plan['sku']);
        $this->assertSame('0001234567890', $candidate->plan['barcode']);
        $this->assertSame('12,34', $candidate->plan['raw_price']);
        $this->assertSame('12.3400', $candidate->plan['base_price']);
        $this->assertSame('review', $candidate->plan['price_state']);
        $this->assertNull($candidate->plan['tax_rate_id']);
        $this->assertSame([['label' => 'Snaga', 'value' => '10 W'], ['label' => 'Boja', 'value' => 'Bijela']], $candidate->plan['attributes']);
        $this->assertDatabaseCount('products', 0);

        $run = $this->service()->import($preview->id, [$candidate->id]);

        $this->assertSame('completed', $run->status);
        $this->assertSame('import_csv', $run->kind);
        $this->assertSame(1, $run->created_count);
        $product = Product::query()->firstOrFail();
        $this->assertSame('00123', $product->sku);
        $this->assertFalse($product->is_active);
        $this->assertSame(0, $product->stock_qty);
        $this->assertEquals(12.34, $product->base_price);
        $this->assertNull($product->tax_rate_id);
        $this->assertSame($manufacturer->id, $product->manufacturer_id);
        $this->assertArrayNotHasKey('eracuni', $product->payload);
        $this->assertSame('00123', $product->payload['ideus_csv']['model']);
        $this->assertTrue($product->payload['ideus_csv']['price_review_required']);
        $this->assertSame('2,5', $product->payload['ideus_csv']['raw_weight']);
        $this->assertNull($product->weight_kg);
        $this->assertSame(2, $product->technicalSpecificationRows()->where('source', 'ideus_csv')->count());
        $this->assertSame('new', $preview->items()->where('identifier', 'NEXT')->first()->status);
        $again = $this->service()->import($preview->id, [$candidate->id]);
        $this->assertSame(0, $again->created_count);
        $this->assertDatabaseCount('products', 1);
        Http::assertNothingSent();
    }

    public function test_ideus_csv_excludes_other_brands_and_existing_or_colliding_identity_without_writing_catalog(): void
    {
        $existing = Product::query()->create(['code' => 'existing', 'sku' => 'CHANGED', 'base_price' => 77, 'payload' => ['opencart' => ['sku' => '00123']]]);
        Product::query()->create(['code' => 'barcode', 'sku' => 'OTHER', 'barcode' => '456']);
        $path = $this->csvFile([$this->csvRow(), $this->csvRow([0 => 'OTHER-BRAND', 8 => '789', 21 => 'Unrelated']), $this->csvRow([0 => 'COLLISION', 8 => '456'])], ',');

        $run = $this->service()->previewSupplierCsv($path);

        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->eligible_count);
        $this->assertSame('existing', $run->items()->where('identifier', '00123')->first()->status);
        $this->assertSame($existing->id, $run->items()->where('identifier', '00123')->first()->product_id);
        $this->assertSame('skipped', $run->items()->where('identifier', 'OTHER-BRAND')->first()->status);
        $this->assertSame('ambiguous', $run->items()->where('identifier', 'COLLISION')->first()->status);
        $this->assertEquals(77, $existing->fresh()->base_price);
        $this->assertDatabaseCount('catalog_attributes', 0);
        Http::assertNothingSent();
    }

    public function test_ideus_csv_rejects_other_schema_conflicting_duplicates_and_later_invalid_identifiers_atomically(): void
    {
        foreach ([
            [array_fill(0, 33, 'other-export')],
            [$this->csvRow(), $this->csvRow([1 => 'Conflict'])],
            [$this->csvRow(), $this->csvRow([0 => '=HYPERLINK("unsafe")', 8 => '456'])],
        ] as $rows) {
            $run = $this->service()->previewSupplierCsv($this->csvFile($rows));

            $this->assertSame('failed', $run->status);
            $this->assertSame(0, $run->items()->count());
            $this->assertStringNotContainsString('erp-private-token', $run->error_message);
            $this->assertDatabaseCount('products', 0);
        }
        Http::assertNothingSent();
    }

    public function test_ideus_csv_invalid_price_creates_only_review_draft_and_selected_ids_are_preview_scoped(): void
    {
        $preview = $this->service()->previewSupplierCsv($this->csvFile([$this->csvRow([3 => '=1+1'])]));
        $other = $this->service()->previewSupplierCsv($this->csvFile([$this->csvRow([0 => 'OTHER'])]));
        $wrong = $this->service()->import($preview->id, [$other->items()->first()->id]);
        $this->assertSame('failed', $wrong->status);
        $this->assertDatabaseCount('products', 0);

        $run = $this->service()->import($preview->id, [$preview->items()->first()->id]);
        $this->assertSame('completed', $run->status);
        $this->assertEquals(0, Product::query()->firstOrFail()->base_price);
        $this->assertTrue(Product::query()->firstOrFail()->payload['ideus_csv']['price_review_required']);
        Http::assertNothingSent();
    }

    public function test_price_apply_respects_shared_spreadsheet_price_lock(): void
    {
        $tax = $this->tax();
        Product::query()->create(['code' => '00123', 'sku' => '00123', 'base_price' => 75, 'tax_rate_id' => $tax->id]);
        $this->feed([$this->row()]);
        $preview = $this->service()->previewUpdates('prices');
        $lock = Cache::lock('herrera-stock-sync:base_price', 900);
        $lock->get();
        try {
            $this->service()->applyUpdates($preview->id, [$preview->items()->first()->id]);
            $this->fail('Concurrent price apply must not start.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ažuriranje cijena', $exception->getMessage());
            $this->assertEquals(75, Product::query()->firstOrFail()->base_price);
        } finally {
            $lock->release();
        }
        $this->assertFalse(Cache::has('herrera-eracuni-catalog'));
    }

    public function test_attribute_cron_refuses_capped_feed_before_any_attribute_write_and_reports_safe_reason(): void
    {
        Product::query()->create(['code' => 'CAP-0', 'sku' => 'CAP-0']);
        $rows = [];
        for ($i = 0; $i < 10000; $i++) {
            $rows[] = $this->row(['productCode' => 'CAP-'.$i]);
        }
        $this->feed($rows);

        $run = $this->service()->syncAttributes(trigger: 'cron');

        $this->assertSame('failed', $run->status);
        $this->assertSame('cron', $run->summary['trigger']);
        $this->assertTrue($run->summary['limit_reached']);
        $this->assertStringContainsString('10.000', $run->error_message);
        $this->assertStringContainsString('uži raspon', $run->error_message);
        $this->assertDatabaseCount('catalog_attributes', 0);
        $this->assertDatabaseCount('catalog_product_specifications', 0);
    }

    public function test_failed_attribute_cron_retains_trigger_for_last_cron_attempt_reporting(): void
    {
        Http::fake(['erp.example.test/*' => Http::response('Unavailable', 503)]);

        $run = $this->service()->syncAttributes(trigger: 'cron');

        $this->assertSame('failed', $run->status);
        $this->assertSame('cron', $run->summary['trigger']);
        $this->assertNotNull($run->completed_at);
        $this->assertDatabaseCount('catalog_attributes', 0);
    }

    private function csvRow(array $replace = []): array
    {
        return array_replace(array_fill(0, 46, ''), [
            0 => '00123', 1 => 'CSV svjetiljka', 3 => '12,34', 8 => '0001234567890',
            19 => "Snaga: 10 W\nBoja: Bijela", 21 => 'Braytron', 44 => '2,5', 45 => '40',
        ], $replace);
    }

    private function csvFile(array $rows, string $delimiter = ';'): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'herrera-ideus-');
        $path = $temporaryPath.'.csv';
        rename($temporaryPath, $path);
        $this->temporaryFiles[] = $path;
        $handle = fopen($path, 'wb');
        $header = array_fill(0, 46, '');
        $header[0] = 'SKU';
        $header[1] = 'Name';
        $header[21] = 'Brand';
        fputcsv($handle, $header, $delimiter, '"', '');
        fputcsv($handle, ['Legacy Generic IDEUS export'], $delimiter, '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, $delimiter, '"', '');
        }
        fclose($handle);

        return $path;
    }

    private function row(array $replace = []): array
    {
        return array_replace([
            'productCode' => '00123', 'name' => 'ERP svjetiljka', 'barCode' => '123', 'grossPrice' => '125,00',
            'currency' => 'EUR', 'vatPercentage' => '25',
            'description' => "Snaga: 10 W\nBoja: Bijela", 'onlineShopVisibility' => 'visibleOnline',
        ], $replace);
    }

    private function feed(array $rows): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['erp.example.test/*' => Http::response(['response' => ['result' => $rows]])]);
    }

    private function tax(): TaxRate
    {
        return TaxRate::query()->create(['code' => 'pdv25', 'name' => 'PDV 25%', 'rate' => 25, 'rate_type' => 'percent', 'is_default' => true, 'is_active' => true]);
    }

    private function service(): EracuniCatalogService
    {
        return app(EracuniCatalogService::class);
    }
}
