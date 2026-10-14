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

    public function test_final_sync_preserves_native_assignment_removal_and_does_not_regrant_it_later(): void
    {
        $this->import();
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        DB::table('admin_customer_assignments')->where('admin_user_id', $manager->id)->delete();
        $report = $this->import(finalSync: true);
        $this->assertSame(1, $report['native_assignment_removals_preserved']);
        $this->assertSame(0, $report['assignments_added']);
        $this->assertSame(0, $report['conflicts']);
        $this->assertDatabaseCount('admin_customer_assignments', 0);
        DB::connection('staff_test_source')->table('oc_customer_to_user')->delete();
        $this->import(finalSync: true);
        DB::connection('staff_test_source')->table('oc_customer_to_user')->insert(['user_id' => 22, 'customer_id' => 7]);
        $again = $this->import(finalSync: true);
        $this->assertSame(1, $again['native_assignment_removals_preserved']);
        $this->assertSame(0, $again['assignments_added']);
        $this->assertDatabaseCount('admin_customer_assignments', 0);
    }

    public function test_final_sync_preserves_native_reassignment_and_adds_only_proven_new_source_pairs(): void
    {
        $this->import();
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        $admin = User::where('admin_username', 'admin')->firstOrFail();
        $originalCustomer = User::where('account_type', 'customer')->firstOrFail();
        DB::table('admin_customer_assignments')->where('admin_user_id', $manager->id)->delete();
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $admin->id, 'customer_user_id' => $originalCustomer->id, 'created_at' => now(), 'updated_at' => now()]);
        $newCustomer = $this->mappedCustomer(8);
        DB::connection('staff_test_source')->table('oc_customer_to_user')->insert(['user_id' => 22, 'customer_id' => 8]);
        $nativeRoles = DB::table('permissions')->orderBy('id')->get()->toJson();
        $report = $this->import(finalSync: true);
        $this->assertSame(1, $report['native_assignment_reassignments_preserved']);
        $this->assertSame(1, $report['assignments_added']);
        $this->assertSame(0, $report['conflicts']);
        $this->assertDatabaseMissing('admin_customer_assignments', ['admin_user_id' => $manager->id, 'customer_user_id' => $originalCustomer->id]);
        $this->assertDatabaseHas('admin_customer_assignments', ['admin_user_id' => $admin->id, 'customer_user_id' => $originalCustomer->id]);
        $this->assertDatabaseHas('admin_customer_assignments', ['admin_user_id' => $manager->id, 'customer_user_id' => $newCustomer->id]);
        $this->assertSame($nativeRoles, DB::table('permissions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, $this->import(finalSync: true)['assignments_added']);
    }

    public function test_final_sync_without_historical_assignment_baseline_reports_ambiguity_without_regrant(): void
    {
        $this->import();
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        $payload = $manager->profile->payload;
        unset($payload['legacy_admin']['assignment_baseline']);
        $manager->profile->update(['payload' => $payload]);
        DB::table('admin_customer_assignments')->where('admin_user_id', $manager->id)->delete();
        $first = $this->import(finalSync: true);
        $this->assertSame(1, $first['assignment_conflicts']);
        $this->assertSame(1, $first['conflicts']);
        $this->assertDatabaseCount('admin_customer_assignments', 0);
        $this->assertArrayNotHasKey('assignment_baseline', $manager->profile->fresh()->payload['legacy_admin']);
        $this->assertSame(1, $this->import(finalSync: true)['conflicts']);
        $this->artisan('herrera:import-staff', ['--source-connection' => 'staff_test_source', '--final-sync' => true])
            ->expectsOutputToContain('assignment_conflicts: 1')->expectsOutputToContain('conflicts: 1')->assertExitCode(1);
    }

    public function test_final_sync_preserves_native_identity_permissions_and_retired_credentials(): void
    {
        $this->import();
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        $manager->forceFill(['name' => 'Native staff name', 'email' => 'native-staff@example.test', 'admin_username' => 'native-login', 'admin_login_enabled' => false, 'password' => 'Native-upgraded-password123!'])->save();
        Bouncer::assign('admin')->to($manager);
        Bouncer::allow($manager)->to('users.access.manage');
        $nativeUser = $manager->fresh()->getRawOriginal();
        $legacy = LegacyCredential::where('user_id', $manager->id)->firstOrFail()->getRawOriginal();
        $permissions = DB::table('permissions')->orderBy('id')->get()->toJson();
        $roles = DB::table('assigned_roles')->orderBy('id')->get()->toJson();
        DB::connection('staff_test_source')->table('oc_user')->where('user_id', 22)->update(['password' => md5('Changed-source-password'), 'firstname' => 'Changed source name']);
        $report = $this->import(finalSync: true);
        $this->assertSame(0, $report['conflicts']);
        $this->assertSame($nativeUser, $manager->fresh()->getRawOriginal());
        $this->assertSame($legacy, LegacyCredential::where('user_id', $manager->id)->firstOrFail()->getRawOriginal());
        $this->assertSame($permissions, DB::table('permissions')->orderBy('id')->get()->toJson());
        $this->assertSame($roles, DB::table('assigned_roles')->orderBy('id')->get()->toJson());
        $this->assertTrue(Hash::check('Native-upgraded-password123!', $manager->fresh()->password));
    }

    public function test_final_sync_refreshes_only_untouched_enabled_legacy_verifiers_without_changing_native_password(): void
    {
        $this->import();
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        $nativePassword = $manager->password;
        DB::connection('staff_test_source')->table('oc_user')->where('user_id', 22)->update(['password' => md5('Latest-original-password')]);
        $source = DB::connection('staff_test_source');
        $before = $source->table('oc_user')->get()->toJson();
        $source->enableQueryLog();
        $report = $this->import(finalSync: true);
        $this->assertSame(1, $report['legacy_credentials_refreshed']);
        $this->assertSame($nativePassword, $manager->fresh()->password);
        $this->assertSame(md5('Latest-original-password'), LegacyCredential::where('user_id', $manager->id)->firstOrFail()->legacy_hash);
        foreach ($source->getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query['query']);
        }
        $source->disableQueryLog();
        $this->assertSame($before, $source->table('oc_user')->get()->toJson());
        $this->assertSame(0, $this->import(finalSync: true)['legacy_credentials_refreshed']);
    }

    public function test_final_sync_imports_new_source_staff_and_unowned_assignments_without_permission_activation(): void
    {
        $this->import();
        $newCustomer = $this->mappedCustomer(8);
        $this->staff(30, 12, 'New Source Rep', 'new-rep@example.test');
        DB::connection('staff_test_source')->table('oc_customer_to_user')->insert(['user_id' => 30, 'customer_id' => 8]);
        $permissions = DB::table('permissions')->orderBy('id')->get()->toJson();
        $report = $this->import(finalSync: true);
        $this->assertSame(1, $report['created']);
        $this->assertSame(1, $report['assignments_added']);
        $this->assertSame(0, $report['conflicts']);
        $rep = User::where('admin_username', 'New Source Rep')->firstOrFail();
        $this->assertTrue($rep->isA('order_manager'));
        $this->assertDatabaseHas('admin_customer_assignments', ['admin_user_id' => $rep->id, 'customer_user_id' => $newCustomer->id]);
        $this->assertDatabaseHas('herrera_import_maps', ['entity' => 'admin_user', 'source_id' => '30', 'target_id' => $rep->id]);
        $this->assertSame(md5('legacy-example'), LegacyCredential::where('user_id', $rep->id)->sole()->legacy_hash);
        $this->assertSame($permissions, DB::table('permissions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, $this->import(finalSync: true)['created']);
    }

    public function test_final_sync_dry_run_is_non_mutating_and_does_not_merge_unmapped_native_staff(): void
    {
        $native = User::factory()->create(['email' => 'admin@example.test']);
        $native->forceFill(['account_type' => 'staff', 'admin_username' => 'native-maintenance'])->save();
        Bouncer::assign('superadmin')->to($native);
        $before = [DB::table('users')->orderBy('id')->get()->toJson(), DB::table('herrera_import_maps')->orderBy('id')->get()->toJson(), DB::table('permissions')->orderBy('id')->get()->toJson()];
        $report = $this->import(apply: false, finalSync: true);
        $this->assertSame(1, $report['identity_conflicts']);
        $this->assertSame(1, $report['created']);
        $this->assertSame($before, [DB::table('users')->orderBy('id')->get()->toJson(), DB::table('herrera_import_maps')->orderBy('id')->get()->toJson(), DB::table('permissions')->orderBy('id')->get()->toJson()]);
        $apply = $this->import(finalSync: true);
        $this->assertSame(1, $apply['identity_conflicts']);
        $this->assertSame('native-maintenance', $native->fresh()->admin_username);
        $this->assertDatabaseMissing('herrera_import_maps', ['entity' => 'admin_user', 'source_id' => '1']);
        $this->assertDatabaseMissing('user_legacy_credentials', ['user_id' => $native->id]);
    }

    public function test_final_sync_surfaces_missing_staff_source_and_source_disable_as_explicit_gates(): void
    {
        $this->import();
        DB::connection('staff_test_source')->table('oc_user')->where('user_id', 1)->delete();
        DB::connection('staff_test_source')->table('oc_user')->where('user_id', 22)->update(['status' => 0]);
        $report = $this->import(finalSync: true);
        $this->assertSame(1, $report['missing_source_staff_conflicts']);
        $this->assertSame(1, $report['source_status_conflicts']);
        $this->assertSame(2, $report['conflicts']);
        $this->assertTrue(User::where('admin_username', 'Manager Test')->firstOrFail()->admin_login_enabled);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_final_sync_rejects_staging_or_remote_source_before_queries_and_live_configuration(): void
    {
        $source = DB::connection('staff_test_source');
        app()->instance('env', 'staging');
        try {
            $this->import(finalSync: true);
            $this->fail('Final sync must never use staging or a live source.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('isolated local rehearsal', $exception->getMessage());
        } finally {
            app()->instance('env', 'testing');
        }
        config(['database.connections.remote_staff_source' => ['driver' => 'mysql', 'host' => 'live.example.test', 'database' => 'never-connect', 'username' => 'unused', 'password' => 'do-not-print']]);
        $this->artisan('herrera:import-staff', ['--source-connection' => 'remote_staff_source', '--final-sync' => true])->expectsOutputToContain('isolated local source and destination')->assertExitCode(1);
        $this->artisan('herrera:import-staff', ['--legacy-config' => '/home/herrera/public_html/upload/config.php', '--final-sync' => true])->expectsOutputToContain('cannot read the live legacy configuration')->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_only_final_sync_accepts_strict_generated_local_rehearsal_database_names(): void
    {
        $target = DB::connection();
        $original = $target->getDatabaseName();
        app()->instance('env', 'local');
        $target->setDatabaseName('herrera_rehearsal_target_20261014_130502_abcd');
        try {
            $this->assertSame(2, $this->import(apply: false, finalSync: true)['staff']);
            try {
                $this->import(apply: false);
                $this->fail('The default staff import must keep its original database restriction.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('restricted', $exception->getMessage());
            }
            $target->setDatabaseName('herrera_rehearsal_target_manual');
            try {
                $this->import(apply: false, finalSync: true);
                $this->fail('An arbitrary rehearsal prefix is not a valid target.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('restricted', $exception->getMessage());
            }
        } finally {
            $target->setDatabaseName($original);
            app()->instance('env', 'testing');
        }
    }

    public function test_final_sync_reports_orphan_source_assignment_and_invalid_native_baseline_without_changing_them(): void
    {
        $this->import();
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        $payload = $manager->profile->payload;
        $payload['legacy_admin']['assignment_baseline'] = ['version' => 1, 'source_customer_ids' => ['unverified'], 'removed_source_customer_ids' => []];
        $manager->profile->update(['payload' => $payload]);
        $before = $manager->profile->getRawOriginal();
        DB::connection('staff_test_source')->table('oc_customer_to_user')->insert(['user_id' => 9999, 'customer_id' => 7]);
        $report = $this->import(finalSync: true);
        $this->assertSame(1, $report['orphan_source_assignments']);
        $this->assertSame(1, $report['baseline_conflicts']);
        $this->assertSame(2, $report['conflicts']);
        $this->assertSame($before, $manager->profile->fresh()->getRawOriginal());
        $this->assertSame(2, $this->import(finalSync: true)['conflicts']);
    }

    public function test_final_sync_rejects_a_new_source_pair_owned_by_native_other_rep_until_review(): void
    {
        $this->import();
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        $admin = User::where('admin_username', 'admin')->firstOrFail();
        $customer = $this->mappedCustomer(8);
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $admin->id, 'customer_user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('staff_test_source')->table('oc_customer_to_user')->insert(['user_id' => 22, 'customer_id' => 8]);
        $report = $this->import(finalSync: true);
        $this->assertSame(1, $report['assignment_conflicts']);
        $this->assertSame(0, $report['assignments_added']);
        $this->assertDatabaseMissing('admin_customer_assignments', ['admin_user_id' => $manager->id, 'customer_user_id' => $customer->id]);
        $this->assertDatabaseHas('admin_customer_assignments', ['admin_user_id' => $admin->id, 'customer_user_id' => $customer->id]);
        $this->assertSame(1, $this->import(finalSync: true)['conflicts']);
    }

    public function test_final_sync_command_applies_local_copy_only_and_prints_aggregate_counts_without_staff_secrets(): void
    {
        $source = DB::connection('staff_test_source');
        $before = $source->table('oc_user')->get()->toJson();
        $code = \Illuminate\Support\Facades\Artisan::call('herrera:import-staff', ['--source-connection' => 'staff_test_source', '--final-sync' => true, '--apply' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('final_sync: 1', $output);
        $this->assertStringContainsString('created: 2', $output);
        $this->assertStringContainsString('conflicts: 0', $output);
        $this->assertStringNotContainsString('Manager Test', $output);
        $this->assertStringNotContainsString('manager@example.test', $output);
        $this->assertStringNotContainsString(md5('legacy-example'), $output);
        $this->assertSame($before, $source->table('oc_user')->get()->toJson());
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('admin_customer_assignments', 1);
    }

    public function test_final_sync_does_not_assign_a_new_source_admin_an_unreviewed_native_wildcard_role(): void
    {
        Bouncer::allow('admin')->everything();
        $permissions = DB::table('permissions')->orderBy('id')->get()->toJson();
        $report = $this->import(finalSync: true);
        $this->assertSame(1, $report['role_conflicts']);
        $this->assertSame(1, $report['conflicts']);
        $this->assertDatabaseMissing('herrera_import_maps', ['entity' => 'admin_user', 'source_id' => '1']);
        $this->assertSame($permissions, DB::table('permissions')->orderBy('id')->get()->toJson());
    }

    public function test_final_sync_remembers_observed_source_pairs_when_native_removal_occurs_after_source_removal(): void
    {
        $this->import();
        $manager = User::where('admin_username', 'Manager Test')->firstOrFail();
        DB::connection('staff_test_source')->table('oc_customer_to_user')->delete();
        $this->assertSame(0, $this->import(finalSync: true)['conflicts']);
        $this->assertDatabaseCount('admin_customer_assignments', 1);
        DB::table('admin_customer_assignments')->where('admin_user_id', $manager->id)->delete();
        $this->assertSame(0, $this->import(finalSync: true)['conflicts']);
        DB::connection('staff_test_source')->table('oc_customer_to_user')->insert(['user_id' => 22, 'customer_id' => 7]);
        $report = $this->import(finalSync: true);
        $this->assertSame(0, $report['assignments_added']);
        $this->assertSame(1, $report['native_assignment_removals_preserved']);
        $this->assertDatabaseCount('admin_customer_assignments', 0);
    }

    private function mappedCustomer(int $sourceId): User
    {
        $customer = User::factory()->create();
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => 'customer', 'source_id' => (string) $sourceId, 'target_id' => $customer->id, 'checksum' => 'test', 'created_at' => now(), 'updated_at' => now()]);

        return $customer;
    }

    private function import(bool $apply = true, bool $finalSync = false): array
    {
        return app(HerreraStaffImportService::class)->import(DB::connection('staff_test_source'), 'oc_', $apply, $finalSync);
    }

    private function staff(int $id, int $group, string $username, string $email): void
    {
        DB::connection('staff_test_source')->table('oc_user')->insert(['user_id' => $id, 'user_group_id' => $group,
            'username' => $username, 'email' => $email, 'firstname' => 'Test', 'lastname' => 'Staff',
            'password' => md5('legacy-example'), 'salt' => '', 'status' => 1]);
    }
}
