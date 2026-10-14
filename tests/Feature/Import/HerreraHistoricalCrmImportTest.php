<?php

namespace Tests\Feature\Import;

use App\Livewire\Admin\User\CustomerCrmManager;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\CustomerSalesSession;
use App\Services\Import\HerreraHistoricalCrmImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraHistoricalCrmImportTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    private User $customer;

    private User $staff;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'herrera-crm-test-');
        config(['database.connections.historical_crm_source' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '']]);
        DB::purge('historical_crm_source');
        $schema = $this->source()->getSchemaBuilder();
        $schema->create('oc_order_manager_sales', function (Blueprint $table): void {
            $table->integer('id')->primary();
            foreach (['start', 'end', 'napomena', 'date_added', 'date_modified'] as $column) {
                $table->text($column)->nullable();
            }
            foreach (['user_id', 'customer_id', 'order_id'] as $column) {
                $table->integer($column);
            }
        });
        $schema->create('oc_order', function (Blueprint $table): void {
            $table->integer('order_id')->primary();
            $table->integer('customer_id');
        });
        $this->customer = User::factory()->create(['account_type' => 'customer']);
        $this->staff = User::factory()->create(['account_type' => 'staff', 'admin_username' => 'crm-staff']);
        $this->order = Order::query()->create(['order_number' => 'OC-HERRERA-500', 'source' => 'opencart_import', 'payload' => ['opencart' => ['order_id' => 500]], 'user_id' => $this->customer->id, 'customer_name' => 'Original buyer', 'customer_email' => 'buyer@example.test']);
        $this->map('customer', 7, $this->customer->id);
        $this->map('admin_user', 22, $this->staff->id);
        $this->map('order', 500, $this->order->id);
        $this->source()->table('oc_order')->insert(['order_id' => 500, 'customer_id' => 7]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('historical_crm_source');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_dry_run_is_non_mutating_and_cli_prints_only_aggregate_counts(): void
    {
        $this->row(1, ['napomena' => 'PRIVATE LEGACY NOTE']);
        $result = $this->import(false);
        $this->assertSame('dry-run', $result['mode']);
        $this->assertSame(1, $result['rows']);
        $this->assertSame(1, $result['created_sessions']);
        $this->assertDatabaseCount('customer_sales_sessions', 0);
        $this->assertDatabaseCount('herrera_source_records', 0);
        $this->assertSame(1, $this->source()->table('oc_order_manager_sales')->count());
        $this->artisan('herrera:import-historical-crm', ['--source-connection' => 'historical_crm_source'])
            ->expectsOutputToContain('mode: dry-run')->doesntExpectOutputToContain('PRIVATE LEGACY NOTE')->assertSuccessful();
    }

    public function test_import_preserves_exact_raw_rows_and_verified_links_without_creating_visits_quotes_or_changing_native_history(): void
    {
        $native = CustomerSalesSession::query()->create(['customer_user_id' => $this->customer->id, 'sales_rep_id' => $this->staff->id, 'started_at' => '2026-01-01 09:00:00', 'ended_at' => '2026-01-01 09:05:00', 'outcome' => 'nothing', 'payload' => ['native' => true]]);
        $before = $native->fresh()->toArray();
        $this->row(1, ['order_id' => 500, 'napomena' => 'Original approval note']);
        $this->row(2);
        $this->row(3, ['end' => '0000-00-00 00:00:00']);
        $raw = (array) $this->source()->table('oc_order_manager_sales')->where('id', 1)->first();

        $result = $this->import();

        $this->assertTrue($result['all_rows_archived']);
        $this->assertSame(3, $result['archived_rows']);
        $this->assertSame(3, $result['mapped_sessions']);
        $this->assertSame(0, $result['review_rows']);
        $session = $this->importedSession(1);
        $this->assertSame($this->customer->id, $session->customer_user_id);
        $this->assertSame($this->staff->id, $session->sales_rep_id);
        $this->assertSame($this->staff->id, $session->performed_by_id);
        $this->assertSame($this->order->id, $session->order_id);
        $this->assertSame('order', $session->outcome);
        $this->assertSame('2025-09-20 10:00:00', $session->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('Original approval note', data_get($session->payload, 'opencart.napomena'));
        $this->assertSame($raw, json_decode(DB::table('herrera_source_records')->where('source_table', 'order_manager_sales')->where('source_key', '1')->value('payload'), true));
        $this->assertSame('nothing', $this->importedSession(2)->outcome);
        $this->assertSame('open', $this->importedSession(3)->outcome);
        $this->assertNull($this->importedSession(3)->ended_at);
        $this->assertSame($before, $native->fresh()->toArray());
        $this->assertDatabaseCount('customer_visits', 0);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('orders', 1);

        $repeat = $this->import();
        $this->assertSame(0, $repeat['created_sessions']);
        $this->assertSame(3, $repeat['unchanged_sessions']);
        $this->assertDatabaseCount('customer_sales_sessions', 4);
        $this->assertDatabaseCount('herrera_source_records', 3);
    }

    public function test_missing_identities_orders_and_invalid_times_are_archived_with_explicit_review_counts_and_no_guessed_links(): void
    {
        $this->row(1, ['customer_id' => 999]);
        $this->row(2, ['start' => '2025-02-30 10:00:00']);
        $this->row(3, ['user_id' => 999]);
        $this->row(4, ['order_id' => 999]);
        $this->row(5, ['end' => '2025-09-20 09:00:00']);
        $other = User::factory()->create(['account_type' => 'customer']);
        $otherOrder = Order::query()->create(['order_number' => 'OC-HERRERA-501', 'source' => 'opencart_import', 'payload' => ['opencart' => ['order_id' => 501]], 'user_id' => $other->id, 'customer_name' => 'Other buyer', 'customer_email' => 'other@example.test']);
        $this->map('order', 501, $otherOrder->id);
        $this->source()->table('oc_order')->insert(['order_id' => 501, 'customer_id' => 8]);
        $this->row(6, ['order_id' => 501]);

        $result = $this->import();

        $this->assertSame(6, $result['archived_rows']);
        $this->assertSame(2, $result['archive_only_rows']);
        $this->assertSame(4, $result['mapped_sessions']);
        $this->assertSame(6, $result['review_rows']);
        foreach (['missing_customer', 'missing_staff', 'missing_order', 'cross_customer_order', 'invalid_start', 'invalid_end'] as $code) {
            $this->assertSame(1, $result['review_counts'][$code]);
        }
        $this->assertNull($this->importedSession(3)->sales_rep_id);
        foreach ([4, 5, 6] as $id) {
            $this->assertSame('unknown', $this->importedSession($id)->outcome);
            $this->assertNull($this->importedSession($id)->order_id);
        }
        $this->assertNull($this->importedSession(5)->ended_at);
        $this->assertSame('2025-09-20 09:00:00', data_get($this->importedSession(5)->payload, 'opencart.end'));
        $this->assertDatabaseCount('customer_visits', 0);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_ambiguous_customer_staff_and_order_mapping_targets_are_not_used(): void
    {
        $this->map('customer', 8, $this->customer->id);
        $this->map('admin_user', 23, $this->staff->id);
        $this->map('order', 501, $this->order->id);
        $this->row(1, ['order_id' => 500]);

        $result = $this->import();

        $this->assertSame(1, $result['archive_only_rows']);
        $this->assertSame(1, $result['review_counts']['ambiguous_customer']);
        $this->assertSame(1, $result['review_counts']['ambiguous_staff']);
        $this->assertSame(1, $result['review_counts']['ambiguous_order']);
        $this->assertDatabaseCount('customer_sales_sessions', 0);
        $this->assertDatabaseCount('herrera_source_records', 1);
    }

    public function test_mispointed_order_mapping_for_the_same_customer_is_unknown_instead_of_a_false_order_link(): void
    {
        $otherOrder = Order::query()->create(['order_number' => 'OC-HERRERA-501', 'source' => 'opencart_import', 'payload' => ['opencart' => ['order_id' => 501]],
            'user_id' => $this->customer->id, 'customer_name' => 'Original buyer', 'customer_email' => 'buyer@example.test']);
        DB::table('herrera_import_maps')->where('entity', 'order')->where('source_id', '500')->update(['target_id' => $otherOrder->id]);
        $this->row(1, ['order_id' => 500]);

        $result = $this->import();

        $this->assertSame(1, $result['review_counts']['order_identity_mismatch']);
        $this->assertSame(1, $result['unknown_sessions']);
        $this->assertSame('unknown', $this->importedSession(1)->outcome);
        $this->assertNull($this->importedSession(1)->order_id);
        $this->assertTrue($result['all_rows_archived']);
    }

    public function test_order_source_payload_and_imported_number_must_each_prove_the_original_order_identity(): void
    {
        $this->row(1, ['order_id' => 500]);
        foreach ([['source' => 'web'], ['order_number' => 'MANUAL-500'], ['payload' => ['opencart' => ['order_id' => 501]]]] as $invalid) {
            $this->order->update(['source' => 'opencart_import', 'order_number' => 'OC-HERRERA-500', 'payload' => ['opencart' => ['order_id' => 500]]]);
            $this->order->update($invalid);

            $result = $this->import();

            $this->assertSame(1, $result['review_counts']['order_identity_mismatch']);
            $this->assertSame('unknown', $this->importedSession(1)->outcome);
            $this->assertNull($this->importedSession(1)->order_id);
        }
    }

    public function test_contradictory_profile_source_identity_cannot_be_used_for_customer_or_staff_links(): void
    {
        $this->customer->profile()->create(['payload' => ['opencart' => ['customer_id' => 8]]]);
        $this->staff->profile()->create(['payload' => ['legacy_admin' => ['source_id' => 23]]]);
        $this->row(1);

        $result = $this->import();

        $this->assertSame(1, $result['archive_only_rows']);
        $this->assertSame(1, $result['review_counts']['ambiguous_customer']);
        $this->assertSame(1, $result['review_counts']['ambiguous_staff']);
        $this->assertDatabaseCount('customer_sales_sessions', 0);
        $this->assertDatabaseCount('herrera_source_records', 1);
    }

    public function test_source_updates_keep_old_raw_versions_and_native_edits_and_gate_overlapping_changes(): void
    {
        $this->row(1);
        $this->import();
        $session = $this->importedSession(1);
        $session->update(['started_at' => '2025-09-20 09:55:00', 'payload' => array_replace($session->payload, ['native_note' => 'Reviewed'])]);
        $this->source()->table('oc_order_manager_sales')->where('id', 1)->update(['end' => '2025-09-20 10:30:00', 'napomena' => 'Source correction']);

        $result = $this->import();

        $this->assertSame(0, $result['conflicts']);
        $this->assertSame(1, $result['native_edits_preserved']);
        $this->assertSame('2025-09-20 09:55:00', $session->fresh()->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2025-09-20 10:30:00', $session->fresh()->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame('Reviewed', data_get($session->fresh()->payload, 'native_note'));
        $this->assertSame(1, DB::table('herrera_source_records')->where('source_table', 'order_manager_sales_history')->count());
        $before = $session->fresh()->toArray();
        $this->source()->table('oc_order_manager_sales')->where('id', 1)->update(['start' => '2025-09-20 10:02:00']);

        $conflict = $this->import();

        $this->assertSame(1, $conflict['conflicts']);
        $this->assertSame($before, $session->fresh()->toArray());
        $this->assertSame(2, DB::table('herrera_source_records')->where('source_table', 'order_manager_sales_history')->count());
    }

    public function test_deleted_mapped_sessions_are_not_recreated_and_missing_source_rows_are_never_deleted(): void
    {
        $this->row(1);
        $this->row(2);
        $this->import();
        $this->importedSession(1)->delete();
        $kept = $this->importedSession(2);
        $this->source()->table('oc_order_manager_sales')->where('id', 2)->delete();

        $result = $this->import();

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(1, $result['missing_source_rows_preserved']);
        $this->assertDatabaseHas('customer_sales_sessions', ['id' => $kept->id]);
        $this->assertDatabaseCount('customer_sales_sessions', 1);
        $this->assertSame(2, DB::table('herrera_source_records')->where('source_table', 'order_manager_sales')->count());
    }

    public function test_source_changes_cannot_attach_an_order_to_a_natively_changed_customer_or_create_negative_duration(): void
    {
        $this->row(1);
        $this->row(2, ['end' => '2025-09-20 10:30:00']);
        $this->import();
        $other = User::factory()->create(['account_type' => 'customer']);
        $this->importedSession(1)->update(['customer_user_id' => $other->id]);
        $this->importedSession(2)->update(['started_at' => '2025-09-20 10:20:00']);
        $before = CustomerSalesSession::query()->orderBy('id')->get()->toArray();
        $this->source()->table('oc_order_manager_sales')->where('id', 1)->update(['order_id' => 500]);
        $this->source()->table('oc_order_manager_sales')->where('id', 2)->update(['end' => '2025-09-20 10:15:00']);

        $result = $this->import();

        $this->assertSame(2, $result['conflicts']);
        $this->assertSame($before, CustomerSalesSession::query()->orderBy('id')->get()->toArray());
        $this->assertNull($this->importedSession(1)->order_id);
    }

    public function test_failed_later_chunk_rolls_back_its_archives_sessions_and_maps_and_resumes_without_duplicates(): void
    {
        for ($id = 1; $id <= 501; $id++) {
            $this->row($id);
        }
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_contains($query->sql, 'insert into "herrera_import_maps"') && in_array('order_manager_sales', $query->bindings, true) && in_array('501', $query->bindings, true)) {
                $fail = false;
                throw new RuntimeException('Simulated late map failure');
            }
        });
        try {
            $this->import();
            $this->fail('The later chunk must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated late map failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('customer_sales_sessions', 500);
        $this->assertSame(500, DB::table('herrera_source_records')->where('source_table', 'order_manager_sales')->count());
        $this->assertSame(500, DB::table('herrera_import_maps')->where('entity', 'order_manager_sales')->count());

        $result = $this->import();

        $this->assertSame(501, $result['archived_rows']);
        $this->assertSame(501, $result['mapped_sessions']);
        $this->assertSame(1, $result['created_sessions']);
        $this->assertDatabaseCount('customer_sales_sessions', 501);
        $this->assertSame(501, DB::table('herrera_import_maps')->where('entity', 'order_manager_sales')->count());
    }

    public function test_unknown_history_outcome_and_original_note_render_in_the_scoped_crm_report(): void
    {
        $this->row(1, ['order_id' => 999, 'napomena' => '<script>original private note</script>']);
        $this->import();
        Bouncer::assign('superadmin')->to($this->staff);

        Livewire::actingAs($this->staff)->test(CustomerCrmManager::class)->set('source', 'sessions')->set('dateFrom', '2025-09-01')->set('dateTo', '2025-09-30')
            ->set('outcome', 'unknown')->assertHasNoErrors()->assertSee('Nepotvrđen ishod')->assertSee('&lt;script&gt;original private note&lt;/script&gt;', false);
    }

    public function test_guards_reject_staging_remote_sources_and_same_database_before_writes(): void
    {
        $this->row(1);
        $this->app['env'] = 'staging';
        try {
            $this->import();
            $this->fail('Staging historical import must be blocked.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('customer_sales_sessions', 0);
        }
        $this->app['env'] = 'testing';
        config(['database.connections.rejected_remote_crm_source' => ['driver' => 'mysql', 'host' => 'remote.invalid', 'database' => 'frozen_source', 'username' => 'unused', 'password' => 'unused']]);
        try {
            app(HerreraHistoricalCrmImportService::class)->import(DB::connection('rejected_remote_crm_source'), 'oc_', true);
            $this->fail('Remote source must be blocked before connecting.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('customer_sales_sessions', 0);
        }
        $this->expectException(RuntimeException::class);
        app(HerreraHistoricalCrmImportService::class)->import(DB::connection(), 'oc_', true);
    }

    public function test_missing_historical_table_is_explicitly_unavailable_and_does_not_claim_complete_archival(): void
    {
        $this->source()->getSchemaBuilder()->drop('oc_order_manager_sales');

        $result = $this->import();

        $this->assertFalse($result['available']);
        $this->assertFalse($result['source_table_present']);
        $this->assertFalse($result['all_rows_archived']);
        $this->assertSame('source_table_missing', $result['unavailable_reason']);
        $this->assertSame(0, $result['conflicts']);
        $this->assertDatabaseCount('customer_sales_sessions', 0);
        $this->assertDatabaseCount('herrera_source_records', 0);
        $this->artisan('herrera:import-historical-crm', ['--source-connection' => 'historical_crm_source'])->assertFailed();
    }

    public function test_incomplete_historical_source_schema_is_unavailable_before_writing_any_rows(): void
    {
        $this->row(1);
        $this->source()->getSchemaBuilder()->table('oc_order_manager_sales', fn (Blueprint $table) => $table->dropColumn('napomena'));

        $result = $this->import();

        $this->assertFalse($result['available']);
        $this->assertTrue($result['source_table_present']);
        $this->assertFalse($result['all_rows_archived']);
        $this->assertSame(1, $result['rows']);
        $this->assertSame('source_schema_or_ids_unverified', $result['unavailable_reason']);
        $this->assertDatabaseCount('customer_sales_sessions', 0);
        $this->assertDatabaseCount('herrera_source_records', 0);
    }

    private function import(bool $apply = true): array
    {
        return app(HerreraHistoricalCrmImportService::class)->import($this->source(), 'oc_', $apply);
    }

    private function source()
    {
        return DB::connection('historical_crm_source');
    }

    private function row(int $id, array $extra = []): void
    {
        $this->source()->table('oc_order_manager_sales')->insert($extra + ['id' => $id, 'start' => '2025-09-20 10:00:00', 'end' => '2025-09-20 10:10:00',
            'napomena' => '', 'user_id' => 22, 'customer_id' => 7, 'order_id' => 0, 'date_added' => '2025-09-20 09:59:00', 'date_modified' => '2025-09-20 10:10:00']);
    }

    private function map(string $entity, int $source, int $target): void
    {
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => (string) $source, 'target_id' => $target, 'checksum' => 'fixture', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function importedSession(int $id): CustomerSalesSession
    {
        $target = DB::table('herrera_import_maps')->where('entity', 'order_manager_sales')->where('source_id', (string) $id)->value('target_id');

        return CustomerSalesSession::query()->findOrFail($target);
    }
}
