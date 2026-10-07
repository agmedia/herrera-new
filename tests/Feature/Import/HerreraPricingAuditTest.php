<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HerreraPricingAuditTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'herrera_pricing_audit_fixture';

    private PriceCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge(self::SOURCE);
        $this->fixture();
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    public function test_full_price_audit_and_all_precedence_samples_are_read_only(): void
    {
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $before = $this->catalog->fresh()->getAttributes();
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '5'])
            ->expectsOutputToContain('PASS: all stored source prices match')
            ->expectsOutputToContain('effective_source_special')
            ->expectsOutputToContain('effective_source_quantity')
            ->expectsOutputToContain('effective_source_customer')
            ->expectsOutputToContain('effective_source_group')
            ->expectsOutputToContain('effective_source_base')
            ->assertSuccessful();
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->catalog->fresh()->getAttributes());
        $this->assertNull(DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
        $this->assertDatabaseCount('catalog_price_catalog_audits', 0);
    }

    public function test_native_precision_loss_fails_the_activation_gate(): void
    {
        DB::table('products')->where('code', 'audit-product-1')->update(['base_price' => '91.2300']);
        DB::table('catalog_price_entries')->where('source_key', 'product:1')->update(['price' => '91.2300']);
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '5'])
            ->expectsOutputToContain('native_base_missing_or_changed')
            ->expectsOutputToContain('entry_price_changed')
            ->expectsOutputToContain('FAIL: pricing is not ready for activation')
            ->assertFailed();
        $this->assertSame('draft', $this->catalog->fresh()->status);
        $this->assertNull(DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
    }

    public function test_incomplete_import_cannot_pass_the_gate(): void
    {
        $this->catalog->update(['metadata' => ['import_complete' => false]]);
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE])
            ->expectsOutput('Pricing import is incomplete. Wait for it to finish before auditing.')
            ->assertFailed();
    }

    public function test_production_environment_is_rejected_before_reading_prices(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE])
            ->expectsOutput('Pricing audit is restricted to local or testing environments.')
            ->assertFailed();
    }

    public function test_non_migration_local_database_is_rejected(): void
    {
        $this->app['env'] = 'local';
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE])
            ->expectsOutput('Local audit target must be herrera_new_migration.')
            ->assertFailed();
    }

    public function test_same_database_connection_is_rejected(): void
    {
        $this->artisan('herrera:audit-pricing', ['--source-connection' => config('database.default')])
            ->expectsOutput('Source and target must be separate databases.')
            ->assertFailed();
    }

    public function test_unexpected_exception_messages_cannot_expose_contract_values(): void
    {
        DB::connection(self::SOURCE)->table('oc_product')->where('product_id', '1')->update(['price' => 'sensitive-contract-value']);
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE])
            ->expectsOutput('Audit stopped safely (Brick\\Math\\Exception\\NumberFormatException). No data was changed.')
            ->doesntExpectOutputToContain('sensitive-contract-value')
            ->assertFailed();
    }

    private function fixture(): void
    {
        $group = CustomerGroup::query()->create(['code' => 'audit-group', 'name' => 'Audit group', 'is_active' => true]);
        $user = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Fixture', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);
        $this->map('customer_group', 1, $group->id);
        $this->map('customer', 1, $user->id);
        $products = [];
        for ($id = 1; $id <= 5; $id++) {
            $products[$id] = Product::query()->create(['code' => 'audit-product-'.$id, 'sku' => 'audit-'.$id, 'base_price' => '91.2344', 'is_active' => true, 'stock_qty' => 100]);
            $this->map('product', $id, $products[$id]->id);
        }
        $this->source('product', ['product_id', 'price'], array_map(fn ($id) => ['product_id' => (string) $id, 'price' => '91.2344'], range(1, 5)));
        $this->source('product_price_by_cigroup', ['product_id', 'group_id', 'category_id', 'price'], [['product_id' => '2', 'group_id' => '1', 'category_id' => '0', 'price' => '90.1234']]);
        $this->source('product_price_by_customer_id', ['product_id', 'customer_id', 'category_id', 'price'], [['product_id' => '3', 'customer_id' => '1', 'category_id' => '0', 'price' => '80.2345']]);
        $this->source('product_discount', ['product_discount_id', 'product_id', 'customer_group_id', 'quantity', 'priority', 'price', 'date_start', 'date_end'], [['product_discount_id' => '1', 'product_id' => '4', 'customer_group_id' => '1', 'quantity' => '5', 'priority' => '1', 'price' => '70.3456', 'date_start' => '0000-00-00', 'date_end' => '0000-00-00']]);
        $this->source('product_special', ['product_special_id', 'product_id', 'customer_group_id', 'priority', 'price', 'date_start', 'date_end'], [['product_special_id' => '1', 'product_id' => '5', 'customer_group_id' => '1', 'priority' => '1', 'price' => '60.4567', 'date_start' => '0000-00-00', 'date_end' => '0000-00-00']]);
        $this->source('customer', ['customer_id', 'customer_group_id', 'status'], [['customer_id' => '1', 'customer_group_id' => '1', 'status' => '1']]);
        $this->catalog = PriceCatalog::query()->create(['name' => 'Fixture', 'status' => 'draft', 'currency_code' => 'EUR', 'source_system' => 'herrera-opencart', 'source_snapshot' => ':memory:', 'metadata' => ['import_complete' => true, 'import_expected_entries' => 9]]);
        $digest = hash_init('sha256');
        foreach (['product' => 'base', 'product_price_by_cigroup' => 'group', 'product_price_by_customer_id' => 'customer', 'product_discount' => 'quantity', 'product_special' => 'special'] as $table => $kind) {
            $query = DB::connection(self::SOURCE)->table('oc_'.$table);
            foreach (array_slice(DB::connection(self::SOURCE)->getSchemaBuilder()->getColumnListing('oc_'.$table), 0, 3) as $column) {
                $query->orderBy($column);
            }
            foreach ($query->get() as $record) {
                $row = (array) $record;
                hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
                $key = $table.':'.($row['product_special_id'] ?? $row['product_discount_id'] ?? ($kind === 'base' ? $row['product_id'] : $row['product_id'].':1:0'));
                $this->catalog->entries()->create(['source_key' => $key, 'product_id' => $products[(int) $row['product_id']]->id, 'kind' => $kind, 'customer_group_id' => in_array($kind, ['group', 'quantity', 'special'], true) ? $group->id : null, 'user_id' => $kind === 'customer' ? $user->id : null, 'minimum_quantity' => (int) ($row['quantity'] ?? 1), 'priority' => (int) ($row['priority'] ?? 0), 'price' => $row['price'], 'is_active' => true, 'payload' => ['opencart' => $row]]);
            }
        }
        $this->catalog->update(['source_checksum' => hash_final($digest)]);
    }

    private function source(string $table, array $columns, array $rows): void
    {
        DB::connection(self::SOURCE)->getSchemaBuilder()->create('oc_'.$table, function (Blueprint $schema) use ($columns): void {
            foreach ($columns as $column) {
                $schema->string($column);
            }
        });
        DB::connection(self::SOURCE)->table('oc_'.$table)->insert($rows);
    }

    private function map(string $entity, int $sourceId, int $targetId): void
    {
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => (string) $sourceId, 'target_id' => $targetId, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
    }
}
