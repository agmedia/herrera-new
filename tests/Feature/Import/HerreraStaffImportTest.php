<?php

namespace Tests\Feature\Import;

use App\Models\User;
use App\Models\User\LegacyCredential;
use App\Services\Import\HerreraStaffImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraStaffImportTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'herrera-staff-test-');
        config(['database.connections.staff_test_source' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '']]);
        DB::purge('staff_test_source');
        $schema = DB::connection('staff_test_source')->getSchemaBuilder();
        $schema->create('oc_user_group', function (Blueprint $table): void {
            $table->integer('user_group_id');
            $table->string('name');
            $table->text('permission');
        });
        $schema->create('oc_user', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('user_group_id');
            foreach (['username', 'email', 'firstname', 'lastname', 'password', 'salt'] as $column) {
                $table->string($column);
            }
            $table->integer('status');
        });
        $schema->create('oc_customer_to_user', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('customer_id');
        });
        $access = ['customer/custom_field', 'customer/customer', 'localisation/country', 'localisation/currency', 'localisation/geo_zone', 'localisation/language', 'sale/order', 'user/api'];
        DB::connection('staff_test_source')->table('oc_user_group')->insert([
            ['user_group_id' => 1, 'name' => 'Administrator', 'permission' => json_encode(['access' => ['user/user', 'user/user_permission'], 'modify' => ['user/user', 'user/user_permission']])],
            ['user_group_id' => 12, 'name' => 'Order Manager', 'permission' => json_encode(['access' => $access, 'modify' => array_values(array_diff($access, ['localisation/geo_zone']))])],
        ]);
        $this->staff(1, 1, 'admin', 'admin@example.test');
        $this->staff(22, 12, 'Manager Test', 'manager@example.test');
        $customer = User::factory()->create(['email' => 'manager@example.test']);
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => 'customer', 'source_id' => '7', 'target_id' => $customer->id, 'checksum' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('staff_test_source')->table('oc_customer_to_user')->insert(['user_id' => 22, 'customer_id' => 7]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('staff_test_source');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_dry_run_makes_no_changes_to_source_or_destination(): void
    {
        $roles = DB::table('roles')->count();
        $report = $this->import(false);
        $this->assertSame(2, $report['staff']);
        $this->assertSame(1, $report['assignments']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('roles', $roles);
        $this->assertDatabaseCount('user_legacy_credentials', 0);
        $this->assertSame(2, DB::connection('staff_test_source')->table('oc_user')->count());
    }

    public function test_import_separates_customer_identity_preserves_passwords_and_scopes_manager(): void
    {
        $customer = User::first();
        $customerPassword = $customer->password;
        $this->import();
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        $this->assertNotSame($manager->id, $customer->id);
        $this->assertSame('staff', $manager->account_type);
        $this->assertTrue($manager->isA('order_manager'));
        $this->assertFalse($manager->can('users.access.manage'));
        $this->assertFalse($manager->can('settings.api.manage'));
        $this->assertTrue($manager->can('sales.orders.update'));
        $this->assertSame($customerPassword, $customer->fresh()->password);
        $this->assertFalse($customer->fresh()->isA('order_manager'));
        $this->assertDatabaseHas('admin_customer_assignments', ['admin_user_id' => $manager->id, 'customer_user_id' => $customer->id]);
        $credential = LegacyCredential::where('user_id', $manager->id)->firstOrFail();
        $this->assertSame(md5('legacy-example'), $credential->legacy_hash);
        $this->assertNotSame($credential->legacy_hash, $credential->getRawOriginal('legacy_hash'));
        $this->assertStringNotContainsString(md5('legacy-example'), json_encode($manager->profile->payload));
        $this->assertTrue(User::where('admin_username', 'admin')->firstOrFail()->can('users.access.manage'));
        $this->import();
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('admin_customer_assignments', 1);
    }

    public function test_existing_native_admin_password_and_retired_legacy_password_stay_intact(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $admin->forceFill(['account_type' => 'staff'])->save();
        Bouncer::assign('superadmin')->to($admin);
        $nativePassword = $admin->password;
        $this->import();
        $this->assertSame($nativePassword, $admin->fresh()->password);
        $this->assertTrue($admin->fresh()->isA('superadmin'));
        $this->assertDatabaseMissing('user_legacy_credentials', ['user_id' => $admin->id]);
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        $manager->password = Hash::make('new-example-password');
        $manager->save();
        $this->import();
        $credential = LegacyCredential::where('user_id', $manager->id)->firstOrFail();
        $this->assertFalse($credential->enabled);
        $this->assertTrue(Hash::check('new-example-password', $manager->fresh()->password));
    }

    public function test_disabled_staff_remains_disabled(): void
    {
        DB::connection('staff_test_source')->table('oc_user')->where('user_id', 22)->update(['status' => 0]);
        $report = $this->import();
        $this->assertSame(1, $report['inactive']);
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        $this->assertFalse($manager->admin_login_enabled);
        $this->assertFalse(LegacyCredential::where('user_id', $manager->id)->firstOrFail()->enabled);
        DB::connection('staff_test_source')->table('oc_user')->where('user_id', 22)->update(['status' => 1]);
        $this->import();
        $this->assertTrue($manager->fresh()->admin_login_enabled);
        $this->assertTrue(LegacyCredential::where('user_id', $manager->id)->firstOrFail()->enabled);
    }

    public function test_unmapped_assigned_customer_aborts_entire_batch(): void
    {
        $roles = DB::table('roles')->count();
        DB::table('herrera_import_maps')->delete();
        try {
            $this->import();
            $this->fail('Unmapped customers must stop the import.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('customer', $e->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('roles', $roles);
    }

    public function test_source_username_email_ambiguity_is_rejected_before_mutation(): void
    {
        DB::connection('staff_test_source')->table('oc_user')->where('user_id', 1)->update(['username' => 'manager@example.test']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('username conflicts');
        $this->import();
    }

    public function test_two_source_accounts_cannot_overwrite_one_existing_staff_identity(): void
    {
        $existing = User::factory()->make(['email' => 'admin@example.test']);
        $existing->forceFill(['account_type' => 'staff', 'admin_username' => 'Manager Test'])->save();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('same destination');
        $this->import();
    }

    public function test_unreviewed_source_permissions_are_rejected(): void
    {
        DB::connection('staff_test_source')->table('oc_user_group')->where('user_group_id', 12)->update(['permission' => json_encode(['access' => ['catalog/product'], 'modify' => ['catalog/product']])]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reviewed mapping');
        $this->import();
    }

    private function import(bool $apply = true): array
    {
        return app(HerreraStaffImportService::class)->import(DB::connection('staff_test_source'), 'oc_', $apply);
    }

    private function staff(int $id, int $group, string $username, string $email): void
    {
        DB::connection('staff_test_source')->table('oc_user')->insert(['user_id' => $id, 'user_group_id' => $group,
            'username' => $username, 'email' => $email, 'firstname' => 'Test', 'lastname' => 'Staff',
            'password' => md5('legacy-example'), 'salt' => '', 'status' => 1]);
    }
}
