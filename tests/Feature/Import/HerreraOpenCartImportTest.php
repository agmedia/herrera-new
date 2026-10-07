<?php

namespace Tests\Feature\Import;

use App\Models\User\LegacyCredential;
use App\Services\Import\HerreraOpenCartImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HerreraOpenCartImportTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'herrera-source-test-');
        config(['database.connections.herrera_test_source' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('herrera_test_source');
        $this->source('language', [['language_id' => 3, 'code' => 'hr-hr']]);
        $this->source('country', [['country_id' => 1, 'iso_code_2' => 'HR']]);
        $this->source('category', [['category_id' => 10, 'parent_id' => 0, 'status' => 1, 'sort_order' => 0, 'top' => 1, 'image' => 'catalog/category.jpg'], ['category_id' => 11, 'parent_id' => 10, 'status' => 1, 'sort_order' => 1, 'top' => 0, 'image' => '']]);
        $this->source('category_description', [['category_id' => 10, 'language_id' => 3, 'name' => 'Kamere', 'description' => '', 'meta_title' => 'Kamere', 'meta_description' => ''], ['category_id' => 11, 'language_id' => 3, 'name' => 'IP kamere', 'description' => '', 'meta_title' => '', 'meta_description' => '']]);
        $this->source('product', [$this->product(30), $this->product(31) + []]);
        $this->source('product_description', [['product_id' => 30, 'language_id' => 3, 'name' => 'Kamera', 'description' => '<p>Opis</p><script>alert(1)</script>', 'meta_title' => 'SEO kamera', 'meta_description' => 'SEO opis'], ['product_id' => 31, 'language_id' => 3, 'name' => 'Kamera', 'description' => '', 'meta_title' => '', 'meta_description' => '']]);
        $this->source('product_to_category', [['product_id' => 30, 'category_id' => 11]]);
        $this->source('product_image', [['product_id' => 30, 'image' => 'catalog/second.jpg', 'sort_order' => 1]]);
        $this->source('customer_group', [['customer_group_id' => 4, 'approval' => 1, 'sort_order' => 1]]);
        $this->source('customer_group_description', [['customer_group_id' => 4, 'language_id' => 3, 'name' => 'Partner', 'description' => '']]);
        $this->source('customer', [['customer_id' => 7, 'customer_group_id' => 4, 'firstname' => 'Test', 'lastname' => 'Partner', 'email' => 'source@example.test', 'telephone' => '+3851234567', 'password' => str_repeat('a', 40), 'salt' => 'old-salt', 'token' => 'must-not-leak', 'newsletter' => 1, 'address_id' => 40, 'custom_field' => '{"1":"Partner d.o.o.","2":"12345678901"}', 'status' => 1, 'approved' => 0, 'date_added' => '2020-01-01 12:00:00']]);
        $this->source('address', [['address_id' => 40, 'customer_id' => 7, 'firstname' => 'Test', 'lastname' => 'Partner', 'company' => 'Partner d.o.o.', 'address_1' => 'Adresa 1', 'address_2' => '', 'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => 1, 'zone_id' => 0, 'custom_field' => '{}'], ['address_id' => 41, 'customer_id' => 7, 'firstname' => 'Test', 'lastname' => 'Partner', 'company' => 'Partner d.o.o.', 'address_1' => 'Adresa 2', 'address_2' => '', 'city' => 'Split', 'postcode' => '21000', 'country_id' => 1, 'zone_id' => 0, 'custom_field' => '{}']]);
        $this->source('product_price_by_cigroup', [['product_id' => 30, 'group_id' => 4, 'price' => '91.2345'], ['product_id' => 999, 'group_id' => 4, 'price' => '1.0000']]);
        $this->source('product_price_by_customer_id', [['product_id' => 30, 'customer_id' => 7, 'price' => '85.6789', 'category_id' => 11]]);
        $this->source('product_special', [['product_special_id' => 1, 'product_id' => 30, 'customer_group_id' => 4, 'priority' => 1, 'price' => '79.9999', 'date_start' => '0000-00-00', 'date_end' => '0000-00-00']]);
        $this->source('seo_url', [['seo_url_id' => 1, 'store_id' => 0, 'language_id' => 3, 'query' => 'product_id=30', 'keyword' => 'stara-kamera'], ['seo_url_id' => 2, 'store_id' => 0, 'language_id' => 3, 'query' => 'category_id=10', 'keyword' => 'kamere']]);
        $this->source('hb_url_preserve', [['id' => 1, 'store_id' => 0, 'language_id' => 3, 'query' => 'product_id=30', 'old_keyword' => 'jos-starija-kamera', 'new_keyword' => 'stara-kamera']]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('herrera_test_source');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_dry_run_is_non_mutating_and_source_target_guard_rejects_same_database(): void
    {
        $stats = app(HerreraOpenCartImportService::class)->import('herrera_test_source', 'oc_', true);
        $this->assertSame(2, $stats['source_product']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('herrera_import_runs', 0);
        $this->expectException(\RuntimeException::class);
        app(HerreraOpenCartImportService::class)->import(config('database.default'), 'oc_', true);
    }

    public function test_full_catalog_customer_price_and_seo_import_is_idempotent_and_preserves_local_edits(): void
    {
        $importer = app(HerreraOpenCartImportService::class);
        $first = $importer->import('herrera_test_source', 'oc_', false, ['catalog', 'customers', 'pricing', 'seo']);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('user_addresses', 2);
        $this->assertDatabaseHas('b2b_accounts', ['status' => 'approved', 'company_name' => 'Partner d.o.o.']);
        $this->assertDatabaseHas('catalog_price_entries', ['kind' => 'customer', 'price' => '85.6789']);
        $this->assertDatabaseHas('catalog_price_entries', ['kind' => 'base', 'minimum_quantity' => 1]);
        $this->assertDatabaseCount('catalog_price_entries', 5);
        $catalog = DB::table('catalog_price_catalogs')->first();
        $this->assertSame('draft', $catalog->status);
        $this->assertTrue(json_decode($catalog->metadata, true)['import_complete']);
        $this->assertSame(5, json_decode($catalog->metadata, true)['import_expected_entries']);
        $this->assertDatabaseHas('herrera_import_issues', ['code' => 'unmapped_scope_or_product']);
        $payload = json_decode(DB::table('products')->where('code', 'herrera-oc-product-30')->value('payload'), true);
        $this->assertSame('catalog/main.jpg', $payload['opencart']['image']);
        $this->assertSame('catalog/second.jpg', $payload['opencart']['gallery_images'][0]['image']);
        $this->assertStringNotContainsString('<script', DB::table('product_translations')->value('description'));
        $profile = DB::table('user_profiles')->first();
        $this->assertStringNotContainsString('password', $profile->payload);
        $this->assertStringNotContainsString('must-not-leak', $profile->payload);
        $credential = LegacyCredential::first();
        $this->assertSame(str_repeat('a', 40), $credential->legacy_hash);
        $this->assertNotSame(str_repeat('a', 40), DB::table('user_legacy_credentials')->value('legacy_hash'));
        $credential->retire();
        DB::table('b2b_accounts')->update(['status' => 'suspended']);
        DB::table('products')->where('code', 'herrera-oc-product-30')->update(['stock_qty' => 777]);
        $importer->import('herrera_test_source', 'oc_', false, ['catalog', 'customers', 'pricing', 'seo']);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_addresses', 2);
        $this->assertDatabaseCount('catalog_price_entries', 5);
        $this->assertDatabaseHas('b2b_accounts', ['status' => 'suspended']);
        $this->assertDatabaseHas('products', ['stock_qty' => 777]);
        $this->assertFalse($credential->fresh()->enabled);
        $this->assertDatabaseHas('herrera_legacy_urls', ['path' => '/jos-starija-kamera', 'destination' => '/product/stara-kamera', 'status' => 'redirect']);
        $this->assertSame(1, $first['draft_price_catalog_id']);
    }

    public function test_existing_local_customer_is_quarantined_and_never_granted_source_business_access(): void
    {
        \App\Models\User::factory()->create(['email' => 'source@example.test']);
        app(HerreraOpenCartImportService::class)->import('herrera_test_source', 'oc_', false, ['customers']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('b2b_accounts', 0);
        $this->assertDatabaseCount('user_legacy_credentials', 0);
        $this->assertDatabaseHas('herrera_import_issues', ['code' => 'existing_email_not_merged']);
    }

    public function test_duplicate_original_barcodes_are_preserved_as_source_metadata_without_fabricating_an_ean(): void
    {
        DB::connection('herrera_test_source')->table('oc_product')->update(['ean' => '1234567890123']);
        app(HerreraOpenCartImportService::class)->import('herrera_test_source', 'oc_', false, ['catalog']);
        $this->assertDatabaseCount('products', 2);
        $this->assertSame(1, DB::table('products')->whereNull('barcode')->count());
        $this->assertDatabaseHas('herrera_import_issues', ['entity' => 'product', 'source_id' => '31', 'code' => 'duplicate_barcode']);
        $payload = json_decode(DB::table('products')->where('code', 'herrera-oc-product-31')->value('payload'), true);
        $this->assertSame('1234567890123', $payload['opencart']['ean']);
    }

    public function test_historical_orders_preserve_status_items_totals_and_history_without_stock_or_notifications(): void
    {
        $this->source('order_status', [['order_status_id' => 1, 'language_id' => 3, 'name' => 'Zaprimljeno']]);
        $order = ['order_id' => 500, 'order_status_id' => 1, 'customer_id' => 7, 'language_id' => 3, 'currency_code' => 'EUR', 'currency_value' => '1.00000000', 'firstname' => 'Test', 'lastname' => 'Partner', 'email' => 'source@example.test', 'telephone' => '+3851234567', 'total' => '46.2960', 'comment' => 'Stara narudžba', 'date_added' => '2020-01-03 12:00:00'];
        foreach (['payment', 'shipping'] as $prefix) {
            foreach (['firstname', 'lastname', 'company', 'address_1', 'address_2', 'postcode', 'city', 'zone', 'code', 'method'] as $field) {
                $order[$prefix.'_'.$field] = 'Historical '.$field;
            }
            $order[$prefix.'_country_id'] = 1;
        }
        $this->source('order', [$order]);
        $this->source('order_product', [['order_product_id' => 600, 'order_id' => 500, 'product_id' => 30, 'sku' => 'SKU-30', 'model' => 'SKU-30', 'name' => 'Stari naziv kamere', 'price' => '12.3456', 'quantity' => 3, 'total' => '37.0368', 'tax' => '3.0864']]);
        $this->source('order_total', [['order_total_id' => 701, 'order_id' => 500, 'code' => 'sub_total', 'title' => 'Međuzbroj', 'value' => '37.0368', 'sort_order' => 0], ['order_total_id' => 702, 'order_id' => 500, 'code' => 'tax', 'title' => 'PDV', 'value' => '9.2592', 'sort_order' => 1]]);
        $this->source('order_history', [['order_history_id' => 800, 'order_id' => 500, 'order_status_id' => 1, 'comment' => 'Zaprimljeno 2020', 'notify' => 1, 'date_added' => '2020-01-03 12:00:00']]);
        $importer = app(HerreraOpenCartImportService::class);
        $importer->import('herrera_test_source', 'oc_', false, ['catalog', 'customers', 'orders']);
        $importer->import('herrera_test_source', 'oc_', false, ['orders']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('order_history', 1);
        $this->assertDatabaseCount('order_totals', 2);
        $this->assertDatabaseHas('orders', ['source' => 'opencart_import', 'order_number' => 'OC-HERRERA-500', 'item_qty' => 3]);
        $this->assertEquals(12.3456, DB::table('order_items')->value('unit_price'));
        $this->assertEquals(37.0368, DB::table('order_items')->value('line_total'));
        $this->assertEquals(9.2592, DB::table('orders')->value('tax_total'));
        $this->assertDatabaseHas('products', ['code' => 'herrera-oc-product-30', 'stock_qty' => 12]);
        $this->assertDatabaseHas('order_history', ['comment' => 'Zaprimljeno 2020', 'created_at' => '2020-01-03 12:00:00']);
    }

    public function test_order_header_chunks_preserve_committed_maps_and_resume_after_atomic_failure(): void
    {
        $orders = array_map(fn (int $id) => $this->historicalOrder($id), range(1000, 1502));
        $this->source('order', $orders);
        $collision = DB::table('orders')->insertGetId(['order_number' => 'OC-HERRERA-1501', 'customer_name' => 'Local customer', 'customer_email' => 'local@example.test']);
        $importer = app(HerreraOpenCartImportService::class);

        try {
            $importer->import('herrera_test_source', 'oc_', false, ['orders']);
            $this->fail('Expected the second order chunk to fail.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(500, DB::table('orders')->where('source', 'opencart_import')->count());
            $this->assertSame(500, DB::table('herrera_import_maps')->where('entity', 'order')->count());
            $this->assertDatabaseMissing('herrera_import_maps', ['entity' => 'order', 'source_id' => '1500']);
            $summary = json_decode(DB::table('herrera_import_runs')->where('status', 'failed')->value('summary'), true);
            $this->assertSame(500, $summary['order_written']);
        }

        DB::table('orders')->where('id', $collision)->update(['order_number' => 'LOCAL-COLLISION']);
        $summary = $importer->import('herrera_test_source', 'oc_', false, ['orders']);
        $this->assertSame(3, $summary['order_written']);
        $this->assertSame(503, DB::table('orders')->where('source', 'opencart_import')->count());
        $this->assertDatabaseCount('orders', 504);
        $this->assertSame(503, DB::table('herrera_import_maps')->where('entity', 'order')->count());
        $importer->import('herrera_test_source', 'oc_', false, ['orders']);
        $this->assertDatabaseCount('orders', 504);
    }

    public function test_failed_child_map_write_rolls_back_whole_child_chunk_and_resumes_without_orphans(): void
    {
        $this->source('order', [$this->historicalOrder(500)]);
        $items = array_map(fn (int $id) => ['order_product_id' => $id, 'order_id' => 500, 'product_id' => 30, 'sku' => 'SKU-30', 'model' => 'SKU-30', 'name' => 'Historical item', 'price' => '12.3456', 'quantity' => 3, 'total' => '37.0368', 'tax' => '3.0864'], range(2000, 2502));
        $this->source('order_product', $items);
        DB::unprepared("CREATE TRIGGER herrera_test_fail_child_map BEFORE INSERT ON herrera_import_maps WHEN NEW.entity = 'order_product' AND NEW.source_id = '2501' BEGIN SELECT RAISE(ABORT, 'test map failure'); END");
        $importer = app(HerreraOpenCartImportService::class);

        try {
            $importer->import('herrera_test_source', 'oc_', false, ['catalog', 'orders']);
            $this->fail('Expected the second child chunk to fail during its map insert.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('order_items', 500);
            $this->assertSame(500, DB::table('herrera_import_maps')->where('entity', 'order_product')->count());
            $this->assertDatabaseMissing('herrera_import_maps', ['entity' => 'order_product', 'source_id' => '2500']);
            $summary = json_decode(DB::table('herrera_import_runs')->where('status', 'failed')->value('summary'), true);
            $this->assertSame(500, $summary['order_product_written']);
        } finally {
            DB::unprepared('DROP TRIGGER herrera_test_fail_child_map');
        }

        $summary = $importer->import('herrera_test_source', 'oc_', false, ['orders']);
        $this->assertSame(3, $summary['order_product_written']);
        $this->assertDatabaseCount('order_items', 503);
        $this->assertSame(503, DB::table('herrera_import_maps')->where('entity', 'order_product')->count());
        $this->assertDatabaseHas('orders', ['order_number' => 'OC-HERRERA-500', 'item_qty' => 1509]);
        $this->assertEquals(12.3456, DB::table('order_items')->orderByDesc('id')->value('unit_price'));
        $this->assertDatabaseHas('products', ['code' => 'herrera-oc-product-30', 'stock_qty' => 12]);
        $importer->import('herrera_test_source', 'oc_', false, ['orders']);
        $this->assertDatabaseCount('order_items', 503);
    }

    public function test_all_historical_order_tables_read_by_primary_key_without_offset_and_cover_sparse_ids(): void
    {
        $this->source('order', array_reverse(array_map(fn (int $id) => $this->historicalOrder($id), range(1000, 2004, 2))));
        $this->source('order_product', array_reverse(array_map(fn (int $id) => ['order_product_id' => $id, 'order_id' => 1000, 'product_id' => 30, 'sku' => 'SKU-30', 'model' => 'SKU-30', 'name' => 'Historical item', 'price' => '12.3456', 'quantity' => 3, 'total' => '37.0368', 'tax' => '3.0864'], range(3000, 4004, 2))));
        $this->source('order_total', array_reverse(array_map(fn (int $id) => ['order_total_id' => $id, 'order_id' => 1000, 'code' => 'sub_total', 'title' => 'Historical total', 'value' => '37.0368', 'sort_order' => 0], range(5000, 6004, 2))));
        $this->source('order_history', array_reverse(array_map(fn (int $id) => ['order_history_id' => $id, 'order_id' => 1000, 'order_status_id' => 0, 'comment' => 'Historical history', 'notify' => 1, 'date_added' => '2020-01-03 12:00:00'], range(7000, 8004, 2))));
        $source = DB::connection('herrera_test_source');
        $source->enableQueryLog();
        app(HerreraOpenCartImportService::class)->import('herrera_test_source', 'oc_', false, ['orders']);
        $queries = $source->getQueryLog();
        $source->disableQueryLog();

        foreach (['order' => 'order_id', 'order_product' => 'order_product_id', 'order_total' => 'order_total_id', 'order_history' => 'order_history_id'] as $table => $key) {
            $reads = array_values(array_filter($queries, fn (array $query) => str_starts_with($query['query'], 'select * from "oc_'.$table.'"')));
            $this->assertCount(2, $reads);
            foreach ($reads as $read) {
                $this->assertStringContainsString('order by "'.$key.'" asc limit 500', $read['query']);
                $this->assertStringNotContainsString('offset', $read['query']);
                $this->assertStringNotContainsString('asc,', $read['query']);
            }
            $this->assertStringContainsString('where "'.$key.'" > ?', $reads[1]['query']);
            $this->assertSame(503, DB::table('herrera_import_maps')->where('entity', $table)->count());
        }
        $this->assertDatabaseCount('orders', 503);
        $this->assertDatabaseCount('order_items', 503);
        $this->assertDatabaseCount('order_totals', 503);
        $this->assertDatabaseCount('order_history', 503);
    }

    public function test_historical_business_identity_uses_order_snapshot_and_preserves_original_foreign_identifier(): void
    {
        $order = array_replace($this->historicalOrder(500), ['custom_field' => '{"1":"Original company at checkout","2":"SI12345678"}', 'payment_company' => '', 'payment_custom_field' => '{}']);
        $this->source('order', [$order]);
        app(HerreraOpenCartImportService::class)->import('herrera_test_source', 'oc_', false, ['customers', 'orders']);
        $this->assertDatabaseHas('user_profiles', ['company' => 'Partner d.o.o.', 'oib' => '12345678901']);
        $this->assertDatabaseHas('orders', ['order_number' => 'OC-HERRERA-500', 'billing_company' => 'Original company at checkout', 'billing_oib' => 'SI12345678']);
    }

    public function test_explicit_order_identity_repair_only_fills_blanks_preserving_money_addresses_and_local_edits(): void
    {
        $orders = [
            array_replace($this->historicalOrder(500), ['custom_field' => '{"1":"Original company at checkout","2":"SI12345678"}', 'payment_company' => '']),
            array_replace($this->historicalOrder(501), ['custom_field' => '{"1":"Another original company","2":"98765432109"}', 'payment_company' => '']),
            array_replace($this->historicalOrder(502), ['custom_field' => '{}', 'payment_company' => '']),
        ];
        $this->source('order', $orders);
        $importer = app(HerreraOpenCartImportService::class);
        $importer->import('herrera_test_source', 'oc_', false, ['customers', 'orders']);
        DB::table('orders')->where('order_number', 'OC-HERRERA-500')->update(['billing_company' => null, 'billing_oib' => null, 'grand_total' => '99.9876', 'customer_note' => 'Local historical note', 'billing_address_line_1' => 'Local invoice address']);
        DB::table('orders')->where('order_number', 'OC-HERRERA-501')->update(['billing_company' => 'Local verified company', 'billing_oib' => 'LOCAL-IDENTIFIER']);
        $importer->import('herrera_test_source', 'oc_', false, ['orders']);
        $this->assertDatabaseHas('orders', ['order_number' => 'OC-HERRERA-500', 'billing_company' => null, 'billing_oib' => null]);

        $summary = $importer->import('herrera_test_source', 'oc_', false, ['order_identity']);
        $this->assertSame(1, $summary['order_identity_written']);
        $this->assertSame(3, $summary['order_identity_checked']);
        $this->assertDatabaseHas('orders', ['order_number' => 'OC-HERRERA-500', 'billing_company' => 'Original company at checkout', 'billing_oib' => 'SI12345678', 'grand_total' => '99.9876', 'customer_note' => 'Local historical note', 'billing_address_line_1' => 'Local invoice address']);
        $this->assertDatabaseHas('orders', ['order_number' => 'OC-HERRERA-501', 'billing_company' => 'Local verified company', 'billing_oib' => 'LOCAL-IDENTIFIER']);
        $this->assertDatabaseHas('orders', ['order_number' => 'OC-HERRERA-502', 'billing_company' => null, 'billing_oib' => null]);
        $this->assertDatabaseHas('herrera_import_issues', ['entity' => 'order', 'source_id' => '501', 'code' => 'historical_identity_local_value_preserved_billing_company']);
        DB::table('orders')->where('order_number', 'OC-HERRERA-500')->update(['billing_company' => null]);
        $summary = $importer->import('herrera_test_source', 'oc_', false, ['order_identity']);
        $this->assertArrayNotHasKey('order_identity_written', $summary);
        $this->assertDatabaseHas('orders', ['order_number' => 'OC-HERRERA-500', 'billing_company' => null]);
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_order_identity_repair_rolls_back_whole_failed_chunk_including_its_maps(): void
    {
        $this->source('order', array_map(fn (int $id) => array_replace($this->historicalOrder($id), ['custom_field' => '{"1":"Original company","2":"12345678901"}', 'payment_company' => '']), range(1000, 1502)));
        $importer = app(HerreraOpenCartImportService::class);
        $importer->import('herrera_test_source', 'oc_', false, ['orders']);
        DB::table('orders')->update(['billing_company' => null, 'billing_oib' => null]);
        DB::unprepared("CREATE TRIGGER herrera_test_fail_identity_map BEFORE INSERT ON herrera_import_maps WHEN NEW.entity = 'order_business_identity' AND NEW.source_id = '1501' BEGIN SELECT RAISE(ABORT, 'test identity map failure'); END");
        try {
            $importer->import('herrera_test_source', 'oc_', false, ['order_identity']);
            $this->fail('Expected the second identity repair chunk to fail.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(500, DB::table('orders')->whereNotNull('billing_company')->count());
            $this->assertSame(500, DB::table('herrera_import_maps')->where('entity', 'order_business_identity')->count());
            $this->assertDatabaseHas('orders', ['order_number' => 'OC-HERRERA-1500', 'billing_company' => null, 'billing_oib' => null]);
        } finally {
            DB::unprepared('DROP TRIGGER herrera_test_fail_identity_map');
        }
        $summary = $importer->import('herrera_test_source', 'oc_', false, ['order_identity']);
        $this->assertSame(3, $summary['order_identity_written']);
        $this->assertSame(503, DB::table('orders')->whereNotNull('billing_company')->count());
        $this->assertSame(503, DB::table('herrera_import_maps')->where('entity', 'order_business_identity')->count());
    }

    public function test_relations_are_native_and_preserve_customer_removed_wishlist_on_repeat(): void
    {
        $this->source('product_related', [['product_id' => 30, 'related_id' => 31], ['product_id' => 30, 'related_id' => 999]]);
        $this->source('customer_wishlist', [['customer_id' => 7, 'product_id' => 30, 'date_added' => '2020-01-02 12:00:00']]);
        $importer = app(HerreraOpenCartImportService::class);
        $importer->import('herrera_test_source', 'oc_', false, ['catalog', 'customers', 'relations']);
        $related = DB::table('products')->where('code', 'herrera-oc-product-31')->value('id');
        $payload = json_decode(DB::table('products')->where('code', 'herrera-oc-product-30')->value('payload'), true);
        $this->assertSame([$related], $payload['related_product_ids']);
        $this->assertSame([31, 999], $payload['opencart']['related_product_ids']);
        $this->assertDatabaseCount('user_wishlist_items', 1);
        DB::table('user_wishlist_items')->delete();
        $importer->import('herrera_test_source', 'oc_', false, ['relations']);
        $this->assertDatabaseCount('user_wishlist_items', 0);
    }

    public function test_deleted_language_content_does_not_overwrite_croatian_or_publish_untranslated_blog_headers(): void
    {
        $this->source('information', [['information_id' => 1, 'status' => 1, 'bottom' => 1, 'sort_order' => 0]]);
        $this->source('information_description', [['information_id' => 1, 'language_id' => 3, 'title' => 'Uvjeti', 'description' => '<p>Hrvatski uvjeti</p>', 'meta_title' => 'Uvjeti', 'meta_description' => ''], ['information_id' => 1, 'language_id' => 4, 'title' => 'Terms', 'description' => '<p>Obsolete English</p>', 'meta_title' => 'Terms', 'meta_description' => '']]);
        $this->source('blog', [['blog_id' => 1, 'status' => 1, 'sort_order' => 0, 'date_added' => '2020-01-01 12:00:00'], ['blog_id' => 2, 'status' => 1, 'sort_order' => 0, 'date_added' => '2020-01-01 12:00:00']]);
        $this->source('blog_description', [['blog_id' => 1, 'language_id' => 3, 'title' => 'Vijesti', 'description' => '<p>Novosti</p>', 'short_description' => 'Novosti', 'page_title' => 'Vijesti', 'meta_description' => '']]);
        app(HerreraOpenCartImportService::class)->import('herrera_test_source', 'oc_', false, ['content']);
        $this->assertDatabaseHas('content_info_page_translations', ['locale' => 'hr', 'title' => 'Uvjeti', 'body_html' => '<p>Hrvatski uvjeti</p>']);
        $this->assertDatabaseCount('content_info_page_translations', 1);
        $this->assertDatabaseHas('herrera_source_records', ['source_table' => 'information_description', 'source_key' => '1:4']);
        $this->assertDatabaseHas('content_blog_posts', ['code' => 'herrera-oc-blog-1', 'is_active' => true]);
        $this->assertDatabaseHas('content_blog_posts', ['code' => 'herrera-oc-blog-2', 'is_active' => false]);
    }

    public function test_billing_prefill_uses_company_oib_and_only_confirmed_structured_original_address(): void
    {
        DB::connection('herrera_test_source')->table('oc_address')->update(['company' => '']);
        DB::connection('herrera_test_source')->table('oc_customer')->update(['custom_field' => '{"1":"Partner d.o.o.","2":"12345678901","3":"Partner d.o.o., Adresa 1, 10000 Zagreb"}']);
        app(HerreraOpenCartImportService::class)->import('herrera_test_source', 'oc_', false, ['customers', 'billing_addresses']);
        $this->assertDatabaseHas('user_addresses', ['type' => 'billing', 'company' => 'Partner d.o.o.', 'oib' => '12345678901', 'address_line_1' => 'Adresa 1', 'city' => 'Zagreb', 'is_default' => true]);
        $this->assertDatabaseCount('user_addresses', 3);
    }

    public function test_ambiguous_free_text_billing_address_is_flagged_not_guessed(): void
    {
        DB::connection('herrera_test_source')->table('oc_customer')->update(['custom_field' => '{"1":"Partner d.o.o.","2":"12345678901","3":"Different street, different town"}']);
        app(HerreraOpenCartImportService::class)->import('herrera_test_source', 'oc_', false, ['customers', 'billing_addresses']);
        $this->assertSame(0, DB::table('user_addresses')->where('type', 'billing')->count());
        $this->assertDatabaseHas('herrera_import_issues', ['entity' => 'customer', 'source_id' => '7', 'code' => 'billing_address_requires_review']);
    }

    public function test_explicit_precision_repair_is_not_default_and_is_forbidden_after_price_activation(): void
    {
        $importer = app(HerreraOpenCartImportService::class);
        $importer->import('herrera_test_source', 'oc_', false, ['catalog', 'customers', 'pricing']);
        DB::table('products')->update(['base_price' => '100.12']);
        $importer->import('herrera_test_source', 'oc_', false, ['base_prices']);
        $this->assertEquals(100.1234, DB::table('products')->value('base_price'));
        DB::table('catalog_price_catalogs')->update(['status' => 'active']);
        $this->expectException(\RuntimeException::class);
        $importer->import('herrera_test_source', 'oc_', false, ['base_prices']);
    }

    private function source(string $table, array $rows): void
    {
        Schema::connection('herrera_test_source')->create('oc_'.$table, function (Blueprint $schema) use ($rows): void {
            foreach (array_keys($rows[0]) as $column) {
                $schema->text($column)->nullable();
            }
        });
        DB::connection('herrera_test_source')->table('oc_'.$table)->insert($rows);
    }

    private function product(int $id): array
    {
        return ['product_id' => $id, 'model' => 'SKU-'.$id, 'sku' => 'SKU-'.$id, 'ean' => '', 'upc' => '', 'manufacturer_id' => 0, 'tax_class_id' => 0, 'status' => 1, 'price' => '100.1234', 'quantity' => 12, 'minimum' => 2, 'weight' => '1.000', 'length' => '10', 'width' => '5', 'height' => '3', 'image' => 'catalog/main.jpg'];
    }

    private function historicalOrder(int $id): array
    {
        $order = ['order_id' => $id, 'order_status_id' => 0, 'customer_id' => 7, 'language_id' => 3, 'currency_code' => 'EUR', 'currency_value' => '1.00000000', 'firstname' => 'Test', 'lastname' => 'Partner', 'email' => 'source@example.test', 'telephone' => '+3851234567', 'total' => '46.2960', 'comment' => 'Historical order', 'date_added' => '2020-01-03 12:00:00'];
        foreach (['payment', 'shipping'] as $prefix) {
            foreach (['firstname', 'lastname', 'company', 'address_1', 'address_2', 'postcode', 'city', 'zone', 'code', 'method'] as $field) {
                $order[$prefix.'_'.$field] = 'Historical '.$field;
            }
            $order[$prefix.'_country_id'] = 1;
        }

        return $order;
    }
}
