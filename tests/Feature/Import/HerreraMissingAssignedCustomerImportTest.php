<?php

namespace Tests\Feature\Import;

use App\Models\User;
use App\Services\Import\HerreraOpenCartImportService;
use App\Services\Import\HerreraStaffImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraMissingAssignedCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    private User $existing;

    private array $groups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'herrera-assigned-source-');
        config(['database.connections.assigned_test_source' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '']]);
        DB::purge('assigned_test_source');
        $this->existing = User::factory()->create(['email' => 'existing@example.test', 'name' => 'Existing local name']);
        $this->map('customer', 1, $this->existing->id);
        foreach ([1, 8] as $sourceId) {
            $id = DB::table('customer_groups')->insertGetId(['code' => 'local-group-'.$sourceId, 'name' => 'Local group '.$sourceId, 'is_active' => true]);
            $this->map('customer_group', $sourceId, $id);
            $this->groups[$sourceId] = (array) DB::table('customer_groups')->find($id);
        }
        $this->source('country', [['country_id' => 1, 'iso_code_2' => 'HR']]);
        $this->source('customer_group', [
            ['customer_group_id' => 1, 'sort_order' => 999], ['customer_group_id' => 8, 'sort_order' => 999],
        ]);
        $this->source('customer_group_description', [
            ['customer_group_id' => 1, 'name' => 'Source changed group', 'description' => 'Source changed description'],
        ]);
        $this->source('customer', [$this->customer(1, 1), $this->customer(1235, 1), $this->customer(1236, 8), $this->customer(2000, 1)]);
        $this->source('address', array_map(fn (int $id): array => $this->address($id), [1, 1235, 1236, 2000]));
        $this->source('customer_to_user', [
            ['user_id' => 1, 'customer_id' => 1], ['user_id' => 22, 'customer_id' => 1235],
            ['user_id' => 22, 'customer_id' => 1236], ['user_id' => 23, 'customer_id' => 1235],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('assigned_test_source');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_only_unmapped_assigned_customers_and_addresses_are_added_without_changing_existing_data(): void
    {
        $original = $this->existing->fresh()->getRawOriginal();
        $source = DB::connection('assigned_test_source');
        $sourceCustomers = $source->table('oc_customer')->get()->toJson();
        $source->enableQueryLog();

        $report = $this->import();

        $queries = $source->getQueryLog();
        $source->disableQueryLog();
        $this->assertSame(['missing_assigned_customers' => 2, 'imported_assigned_customers' => 2, 'imported_assigned_addresses' => 2], $report);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('user_addresses', 2);
        $this->assertDatabaseCount('b2b_accounts', 2);
        $this->assertDatabaseCount('user_legacy_credentials', 2);
        $this->assertSame($original, $this->existing->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('users', ['email' => 'customer2000@example.test']);
        foreach ($this->groups as $sourceId => $originalGroup) {
            $this->assertSame($originalGroup, (array) DB::table('customer_groups')->find($originalGroup['id']));
            $this->assertDatabaseHas('b2b_accounts', ['erp_customer_id' => $sourceId === 1 ? '1235' : '1236', 'customer_group_id' => $originalGroup['id'], 'status' => 'approved']);
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query['query']);
            if (str_contains($query['query'], 'select * from "oc_customer"') || str_contains($query['query'], 'select * from "oc_address"')) {
                $this->assertStringContainsString('"customer_id" in', $query['query']);
            }
        }
        $this->assertSame($sourceCustomers, $source->table('oc_customer')->get()->toJson());

        $newCustomer = User::where('email', 'customer1235@example.test')->firstOrFail();
        $newCustomer->forceFill(['password' => 'Locally-changed-password123!'])->save();
        $changed = $newCustomer->getRawOriginal();
        $this->assertSame(0, $this->import()['missing_assigned_customers']);
        $this->assertSame($changed, $newCustomer->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 3);
    }

    public function test_a_late_failure_rolls_back_the_entire_prerequisite_and_allows_clean_retry(): void
    {
        DB::unprepared("CREATE TRIGGER assigned_customer_fail BEFORE INSERT ON b2b_accounts WHEN NEW.erp_customer_id = '1236' BEGIN SELECT RAISE(ABORT, 'test prerequisite rollback'); END");
        $importer = app(HerreraOpenCartImportService::class);
        try {
            $importer->importMissingAssignedCustomers('assigned_test_source');
            $this->fail('Expected a late prerequisite failure.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('user_profiles', 0);
            $this->assertDatabaseCount('b2b_accounts', 0);
            $this->assertDatabaseCount('user_legacy_credentials', 0);
            $this->assertDatabaseMissing('herrera_import_maps', ['entity' => 'customer', 'source_id' => '1235']);
        }
        DB::unprepared('DROP TRIGGER assigned_customer_fail');
        $this->assertSame(2, $importer->importMissingAssignedCustomers('assigned_test_source')['imported_assigned_customers']);
    }

    #[DataProvider('invalidPrerequisites')]
    public function test_incomplete_or_conflicting_prerequisites_stop_without_changes(string $reason): void
    {
        $source = DB::connection('assigned_test_source');
        if ($reason === 'disabled') {
            $source->table('oc_customer')->where('customer_id', 1236)->update(['status' => 0]);
        } elseif ($reason === 'missing_group') {
            DB::table('herrera_import_maps')->where('entity', 'customer_group')->where('source_id', '8')->delete();
        } elseif ($reason === 'missing_customer') {
            $source->table('oc_customer_to_user')->insert(['user_id' => 22, 'customer_id' => 9999]);
        } else {
            $source->table('oc_customer')->where('customer_id', 1236)->update(['email' => $this->existing->email]);
        }
        try {
            $this->import();
            $this->fail('Expected prerequisite validation failure.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('b2b_accounts', 0);
            $this->assertDatabaseCount('user_legacy_credentials', 0);
        }
    }

    public static function invalidPrerequisites(): array
    {
        return [['disabled'], ['missing_group'], ['missing_customer'], ['email_collision']];
    }

    public function test_narrow_staging_exception_does_not_enable_the_normal_import(): void
    {
        $target = DB::connection();
        $database = $target->getDatabaseName();
        $target->setDatabaseName('herrera_redesign');
        app()->instance('env', 'staging');
        config(['app.url' => 'https://herrera.herrera.hr']);
        try {
            $this->assertSame(2, $this->import()['imported_assigned_customers']);
            try {
                app(HerreraOpenCartImportService::class)->import('assigned_test_source', 'oc_', false, ['customers']);
                $this->fail('Normal staging import must remain prohibited.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('local and testing', $e->getMessage());
            }
            config(['app.url' => 'https://other.example.test']);
            try {
                $this->import();
                $this->fail('Other staging sites must remain prohibited.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('verified Herrera', $e->getMessage());
            }
        } finally {
            $target->setDatabaseName($database);
            app()->instance('env', 'testing');
        }
    }

    public function test_command_requires_apply_and_rolls_back_prerequisite_when_staff_plan_fails(): void
    {
        $this->artisan('herrera:import-staff', ['--source-connection' => 'assigned_test_source', '--include-missing-customers' => true])
            ->expectsOutputToContain('requires --apply')->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->mock(HerreraStaffImportService::class)->shouldReceive('import')->once()->andThrow(new \RuntimeException('Staff plan rejected.'));

        $this->artisan('herrera:import-staff', ['--source-connection' => 'assigned_test_source', '--include-missing-customers' => true, '--apply' => true])
            ->expectsOutputToContain('Staff plan rejected.')->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_legacy_credentials', 0);
        $this->assertDatabaseMissing('herrera_import_maps', ['entity' => 'customer', 'source_id' => '1235']);
    }

    private function import(): array
    {
        return app(HerreraOpenCartImportService::class)->importMissingAssignedCustomers('assigned_test_source');
    }

    private function map(string $entity, int $sourceId, int $targetId): void
    {
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => (string) $sourceId, 'target_id' => $targetId, 'checksum' => 'test']);
    }

    private function source(string $table, array $rows): void
    {
        DB::connection('assigned_test_source')->getSchemaBuilder()->create('oc_'.$table, function (Blueprint $schema) use ($rows): void {
            foreach (array_keys($rows[0]) as $column) {
                $schema->text($column)->nullable();
            }
        });
        DB::connection('assigned_test_source')->table('oc_'.$table)->insert($rows);
    }

    private function customer(int $id, int $group): array
    {
        return ['customer_id' => $id, 'customer_group_id' => $group, 'firstname' => 'Source', 'lastname' => 'Customer '.$id,
            'email' => 'customer'.$id.'@example.test', 'telephone' => '', 'password' => md5('Fixture-password123!'), 'salt' => '',
            'token' => 'fixture-secret', 'newsletter' => 0, 'address_id' => $id * 10, 'custom_field' => json_encode(['1' => 'Company '.$id, '2' => str_pad((string) $id, 11, '0', STR_PAD_LEFT)]),
            'status' => 1, 'date_added' => '2026-10-08 08:00:00'];
    }

    private function address(int $id): array
    {
        return ['address_id' => $id * 10, 'customer_id' => $id, 'firstname' => 'Source', 'lastname' => 'Customer '.$id,
            'company' => 'Company '.$id, 'address_1' => 'Test street', 'address_2' => '', 'city' => 'Zagreb', 'postcode' => '10000', 'country_id' => 1, 'custom_field' => '{}'];
    }
}
