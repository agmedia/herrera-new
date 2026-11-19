<?php

namespace Tests\Feature\Import;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesReportEvidenceMigrationTest extends TestCase
{
    public function test_mysql_checks_order_links_on_both_sides_of_the_inplace_alter(): void
    {
        config(['database.connections.sales_evidence_mysql' => [
            'driver' => 'mysql', 'database' => 'sales_evidence_test', 'version' => '8.0.36',
        ]]);
        DB::usingConnection('sales_evidence_mysql', function () {
            $migration = require database_path('migrations/2026_11_19_091000_add_sales_report_evidence.php');
            $queries = array_column(DB::connection()->pretend(fn () => $migration->up()), 'query');
            $orderDdl = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'ADD CONSTRAINT orders_sales_session_id_foreign')));
            $this->assertCount(1, $orderDdl);
            $this->assertStringContainsString('ON DELETE SET NULL, ALGORITHM=INPLACE', $orderDdl[0]);
            $alter = array_search($orderDdl[0], $queries, true);
            $this->assertSame('SET @herrera_orders_fk_checks = @@SESSION.foreign_key_checks', $queries[$alter - 2]);
            $this->assertSame('SET SESSION foreign_key_checks = 0', $queries[$alter - 1]);
            $this->assertSame('SET SESSION foreign_key_checks = @herrera_orders_fk_checks', $queries[$alter + 1]);
            $checks = array_keys(array_filter($queries, fn ($sql) => str_contains($sql, 'left join `customer_sales_sessions`')));
            $this->assertCount(2, $checks);
            $this->assertTrue($checks[0] < $alter && $checks[1] > $alter + 1);
        });
    }

    public function test_interrupted_migration_preserves_data_and_finishes_missing_keys_and_indexes(): void
    {
        config(['database.connections.sales_evidence_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::usingConnection('sales_evidence_test', function () {
            $schema = Schema::connection('sales_evidence_test');
            $db = $schema->getConnection();
            $migration = require database_path('migrations/2026_11_19_091000_add_sales_report_evidence.php');

            foreach (['users', 'products'] as $table) {
                $schema->create($table, fn (Blueprint $table) => $table->id());
                $db->table($table)->insert(['id' => 1]);
            }
            $schema->create('customer_visits', function (Blueprint $table) {
                $table->id();
                $table->json('location')->nullable();
            });
            $location = '{"latitude":45.8,"longitude":16.0}';
            $db->table('customer_visits')->insert(['id' => 1, 'location' => $location]);
            $schema->create('orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id');
                $table->foreignId('sales_session_id')->nullable();
            });
            $schema->create('customer_sales_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->nullable();
                $table->foreignId('customer_user_id');
            });
            $db->table('orders')->insert([
                ['id' => 1, 'user_id' => 1, 'sales_session_id' => null],
                ['id' => 2, 'user_id' => 1, 'sales_session_id' => 19],
                ['id' => 3, 'user_id' => 1, 'sales_session_id' => null],
            ]);
            foreach ([[11, 1], [12, 2], [13, 3], [14, 3]] as [$id, $order]) {
                $db->table('customer_sales_sessions')->insert(['id' => $id, 'order_id' => $order, 'customer_user_id' => 1]);
            }

            $schema->create('customer_product_presentations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('sales_rep_id')->nullable();
                $table->foreignId('performed_by_id')->nullable();
                $table->foreignId('product_id')->nullable();
                $table->string('sku');
                $table->string('product_name');
                $table->uuid('batch');
                $table->text('note')->nullable();
                $table->json('location')->nullable();
                $table->timestamp('presented_at');
            });
            $db->table('customer_product_presentations')->insert([
                'id' => 1, 'customer_user_id' => 1, 'sales_rep_id' => 1, 'product_id' => 1, 'sku' => 'A123',
                'product_name' => 'Lampa', 'batch' => '7bde3afe-884f-481e-9b65-f3cb2f796976', 'location' => $location,
                'presented_at' => '2026-11-19 10:30:00',
            ]);

            try {
                $migration->up();
                $this->fail('An order referencing a missing session was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Order 2 references missing customer sales session 19.', $exception->getMessage());
            }
            $this->assertSame(19, $db->table('orders')->where('id', 2)->value('sales_session_id'));
            $db->table('customer_sales_sessions')->insert(['id' => 19, 'order_id' => null, 'customer_user_id' => 1]);
            $migration->up();
            $migration->up();

            $this->assertSame($location, $db->table('customer_visits')->value('location'));
            $this->assertSame($location, $db->table('customer_product_presentations')->sole()->location);
            $this->assertSame([11, 19, null], $db->table('orders')->orderBy('id')->pluck('sales_session_id')->all());
            $this->assertCount(1, $schema->getForeignKeys('orders'));
            $this->assertCount(4, $schema->getForeignKeys('customer_product_presentations'));
            $this->assertTrue($schema->hasIndex('customer_product_presentations', ['presented_at']));
            $this->assertTrue($schema->hasIndex('customer_product_presentations', ['customer_user_id', 'sales_rep_id', 'batch', 'product_id'], 'unique'));
            $db->table('customer_sales_sessions')->where('id', 11)->delete();
            $this->assertNull($db->table('orders')->where('id', 1)->value('sales_session_id'));
            $migration->down();
            $this->assertFalse($schema->hasColumn('orders', 'sales_session_id'));
            $this->assertSame(3, $db->table('orders')->count());
        });
    }
}
