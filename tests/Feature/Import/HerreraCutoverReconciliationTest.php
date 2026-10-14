<?php

namespace Tests\Feature\Import;

use App\Console\Commands\HerreraRehearseCutover;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class HerreraCutoverReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'rehearsal_source_test';

    private array $targets = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! collect(DB::select('PRAGMA database_list'))->contains(fn ($database) => $database->name === self::SOURCE)) {
            DB::statement("ATTACH DATABASE ':memory:' AS ".self::SOURCE);
        }
        foreach (['product' => 'product_id INTEGER PRIMARY KEY', 'category' => 'category_id INTEGER PRIMARY KEY',
            'manufacturer' => 'manufacturer_id INTEGER PRIMARY KEY', 'customer' => 'customer_id INTEGER PRIMARY KEY, email TEXT',
            'order' => 'order_id INTEGER PRIMARY KEY, customer_id INTEGER, total DECIMAL(19,4)',
            'order_product' => 'order_product_id INTEGER PRIMARY KEY, order_id INTEGER, price DECIMAL(19,4), total DECIMAL(19,4), tax DECIMAL(19,4), quantity INTEGER',
            'order_total' => 'order_total_id INTEGER PRIMARY KEY, order_id INTEGER, value DECIMAL(19,4)',
            'order_history' => 'order_history_id INTEGER PRIMARY KEY, order_id INTEGER'] as $entity => $columns) {
            DB::statement('CREATE TABLE '.self::SOURCE.'.oc_'.$entity.' ('.$columns.')');
        }
        foreach (['product' => ['product_id', 'products'], 'category' => ['category_id', 'categories'],
            'manufacturer' => ['manufacturer_id', 'catalog_manufacturers']] as $entity => [$key, $table]) {
            $this->source($entity, [$key => 1]);
            $this->map($entity, 1, DB::table($table)->insertGetId(['code' => 'reconciliation-'.$entity]));
        }
        $customer = User::factory()->create(['account_type' => 'customer', 'email' => 'reconcile@example.test']);
        $this->source('customer', ['customer_id' => 7, 'email' => $customer->email]);
        $this->map('customer', 7, $customer->id);
        foreach ([500, 501] as $index => $sourceOrder) {
            $this->source('order', ['order_id' => $sourceOrder, 'customer_id' => 7, 'total' => '10.1234']);
            $order = DB::table('orders')->insertGetId(['order_number' => 'OC-HERRERA-'.$sourceOrder,
                'source' => 'opencart_import', 'user_id' => $customer->id, 'customer_name' => 'Buyer',
                'customer_email' => $customer->email, 'grand_total' => '10.1234', 'payload' => $this->payload('order_id', $sourceOrder)]);
            $this->map('order', $sourceOrder, $order);
            $item = 60 + $index;
            $this->source('order_product', ['order_product_id' => $item, 'order_id' => $sourceOrder, 'price' => '10.1234', 'total' => '10.1234', 'tax' => '2.5309', 'quantity' => 1]);
            $this->map('order_product', $item, DB::table('order_items')->insertGetId(['order_id' => $order, 'name' => 'Original item',
                'unit_price' => '10.1234', 'line_total' => '10.1234', 'quantity' => 1, 'tax_amount' => '2.5309', 'payload' => $this->payload('order_product_id', $item)]));
            $total = 70 + $index;
            $this->source('order_total', ['order_total_id' => $total, 'order_id' => $sourceOrder, 'value' => '10.1234']);
            $this->map('order_total', $total, DB::table('order_totals')->insertGetId(['order_id' => $order, 'code' => 'total', 'title' => 'Total',
                'value' => '10.1234', 'payload' => $this->payload('order_total_id', $total)]));
            $history = 80 + $index;
            $this->source('order_history', ['order_history_id' => $history, 'order_id' => $sourceOrder]);
            $this->map('order_history', $history, DB::table('order_history')->insertGetId(['order_id' => $order, 'payload' => $this->payload('order_history_id', $history)]));
        }
    }

    public function test_valid_source_identity_financials_and_parent_links_pass_without_writing(): void
    {
        $this->assertNotContains(false, $this->reconcile(), true);
    }

    public function test_a_swapped_equal_price_child_map_cannot_pass_on_amounts_and_counts_alone(): void
    {
        DB::table('herrera_import_maps')->where('entity', 'order_product')->where('source_id', '60')->update(['target_id' => $this->targets['order_product'][61]]);
        DB::table('herrera_import_maps')->where('entity', 'order_product')->where('source_id', '61')->update(['target_id' => $this->targets['order_product'][60]]);

        $result = $this->reconcile();

        $this->assertTrue($result['order_product_linked']);
        $this->assertTrue($result['order_product_one_to_one']);
        foreach (['order_item_unit_prices_exact', 'order_item_line_totals_exact', 'order_item_taxes_exact'] as $gate) {
            $this->assertTrue($result[$gate]);
        }
        $this->assertFalse($result['order_product_source_identity_verified']);
        $this->assertFalse($result['order_product_parent_links_exact']);
    }

    public function test_duplicate_target_mappings_fail_even_when_all_source_rows_join_matching_amounts(): void
    {
        DB::table('herrera_import_maps')->where('entity', 'order_product')->where('source_id', '60')->update(['target_id' => $this->targets['order_product'][61]]);

        $result = $this->reconcile();

        $this->assertTrue($result['order_product_linked']);
        $this->assertTrue($result['order_item_unit_prices_exact']);
        $this->assertFalse($result['order_product_one_to_one']);
    }

    #[DataProvider('children')]
    public function test_native_child_parent_edits_gate_review_while_preserving_the_rows(string $entity, string $table, int $id): void
    {
        DB::table($table)->where('id', $this->targets[$entity][$id])->update(['order_id' => $this->targets['order'][501]]);

        $result = $this->reconcile();

        $this->assertTrue($result[$entity.'_source_identity_verified']);
        $this->assertFalse($result[$entity.'_parent_links_exact']);
        $this->assertDatabaseHas($table, ['id' => $this->targets[$entity][$id], 'order_id' => $this->targets['order'][501]]);
    }

    public static function children(): array
    {
        return [['order_product', 'order_items', 60], ['order_total', 'order_totals', 70], ['order_history', 'order_history', 80]];
    }

    public function test_order_source_number_payload_and_customer_links_are_verified(): void
    {
        $other = User::factory()->create(['account_type' => 'customer']);
        DB::table('orders')->where('id', $this->targets['order'][500])->update(['source' => 'web', 'order_number' => 'NATIVE-500',
            'payload' => $this->payload('order_id', 501), 'user_id' => $other->id]);

        $result = $this->reconcile();

        $this->assertTrue($result['order_totals_exact']);
        $this->assertFalse($result['order_source_identity_verified']);
        $this->assertFalse($result['order_customer_links_exact']);
    }

    public function test_guest_or_original_orphan_order_keeps_a_null_native_customer(): void
    {
        DB::table(self::SOURCE.'.oc_order')->where('order_id', 500)->update(['customer_id' => 999]);
        DB::table('orders')->where('id', $this->targets['order'][500])->update(['user_id' => null]);

        $this->assertTrue($this->reconcile()['order_customer_links_exact']);
    }

    public function test_changed_quantity_fails_even_when_stored_unit_and_line_prices_still_match(): void
    {
        DB::table('order_items')->where('id', $this->targets['order_product'][60])->update(['quantity' => 2]);

        $result = $this->reconcile();

        $this->assertTrue($result['order_item_unit_prices_exact']);
        $this->assertTrue($result['order_item_line_totals_exact']);
        $this->assertFalse($result['order_item_quantities_exact']);
    }

    private function reconcile(): array
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|REPLACE)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $result = (new ReflectionMethod(HerreraRehearseCutover::class, 'reconcile'))->invoke(new HerreraRehearseCutover, self::SOURCE, 'oc_');
        $this->assertSame([], $writes);

        return $result;
    }

    private function source(string $entity, array $row): void
    {
        DB::table(self::SOURCE.'.oc_'.$entity)->insert($row);
    }

    private function map(string $entity, int $source, int $target): void
    {
        $this->targets[$entity][$source] = $target;
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => (string) $source,
            'target_id' => $target, 'checksum' => 'fixture', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(string $key, int $id): string
    {
        return json_encode(['opencart' => [$key => $id]], JSON_THROW_ON_ERROR);
    }
}
