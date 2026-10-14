<?php

namespace Tests\Feature\Import;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class HerreraHistoricalCrmPayloadMigrationTest extends TestCase
{
    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = DB::getDefaultConnection();
        foreach (['crm_migration_original', 'crm_migration_clone'] as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:']]);
            Schema::connection($name)->create('customer_sales_sessions', fn (Blueprint $table) => $table->id());
        }
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        Schema::clearResolvedInstance('db.schema');
        DB::purge('crm_migration_original');
        DB::purge('crm_migration_clone');
        parent::tearDown();
    }

    public function test_up_twice_keeps_existing_nullable_json_and_its_data(): void
    {
        DB::setDefaultConnection('crm_migration_clone');
        $migration = $this->migration();
        $migration->up();
        DB::connection('crm_migration_clone')->table('customer_sales_sessions')->insert(['payload' => '{"native_note":"keep"}']);

        $migration->up();

        $this->assertTrue(Schema::connection('crm_migration_clone')->hasColumn('customer_sales_sessions', 'payload'));
        $this->assertSame('{"native_note":"keep"}', DB::connection('crm_migration_clone')->table('customer_sales_sessions')->sole()->payload);
        $this->assertCount(2, Schema::connection('crm_migration_clone')->getColumns('customer_sales_sessions'));
        $this->assertFalse(Schema::connection('crm_migration_original')->hasColumn('customer_sales_sessions', 'payload'));
    }

    public function test_up_uses_current_default_after_schema_facade_was_resolved_on_the_original(): void
    {
        DB::setDefaultConnection('crm_migration_original');
        $this->assertTrue(Schema::hasTable('customer_sales_sessions'));
        $this->assertSame('crm_migration_original', Schema::getConnection()->getName());
        DB::setDefaultConnection('crm_migration_clone');

        $this->migration()->up();

        $this->assertTrue(Schema::connection('crm_migration_clone')->hasColumn('customer_sales_sessions', 'payload'));
        $this->assertFalse(Schema::connection('crm_migration_original')->hasColumn('customer_sales_sessions', 'payload'));
    }

    public function test_explicit_migration_connection_overrides_default_and_down_uses_the_same_connection(): void
    {
        DB::setDefaultConnection('crm_migration_original');
        Schema::hasTable('customer_sales_sessions');
        $migration = $this->migration();
        (new ReflectionProperty($migration, 'connection'))->setValue($migration, 'crm_migration_clone');

        $migration->up();

        $this->assertTrue(Schema::connection('crm_migration_clone')->hasColumn('customer_sales_sessions', 'payload'));
        $this->assertFalse(Schema::connection('crm_migration_original')->hasColumn('customer_sales_sessions', 'payload'));
        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::connection('crm_migration_clone')->hasColumn('customer_sales_sessions', 'payload'));
    }

    public function test_existing_wrong_column_type_fails_without_changing_the_schema_or_data(): void
    {
        DB::setDefaultConnection('crm_migration_clone');
        Schema::connection('crm_migration_clone')->table('customer_sales_sessions', fn (Blueprint $table) => $table->integer('payload')->nullable());
        DB::connection('crm_migration_clone')->table('customer_sales_sessions')->insert(['payload' => 42]);
        try {
            $this->migration()->up();
            $this->fail('A conflicting payload type must require review.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nullable JSON', $exception->getMessage());
        }
        $this->assertSame('integer', Schema::connection('crm_migration_clone')->getColumnType('customer_sales_sessions', 'payload'));
        $this->assertSame(42, DB::connection('crm_migration_clone')->table('customer_sales_sessions')->sole()->payload);
    }

    public function test_existing_non_nullable_json_requires_review_without_altering_it(): void
    {
        DB::setDefaultConnection('crm_migration_clone');
        Schema::connection('crm_migration_clone')->table('customer_sales_sessions', fn (Blueprint $table) => $table->json('payload'));
        $this->expectException(RuntimeException::class);

        $this->migration()->up();
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_10_14_110000_add_payload_to_customer_sales_sessions.php');
    }
}
