<?php

namespace Tests\Feature\Import;

use App\Console\Commands\HerreraPricingAudit;
use App\Data\Pricing\ResolvedB2BPrice;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\PriceCatalogResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
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
            ->expectsOutputToContain('PASS: supported charged-price tables match')
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

    #[DataProvider('unsupportedChargedTables')]
    public function test_nonempty_unsupported_charged_tables_fail_without_mutating_or_printing_prices(string $table): void
    {
        $this->source($table, ['product_id', 'price'], [['product_id' => '1', 'price' => 'PRIVATE CONTRACT AMOUNT']]);
        $before = $this->databaseSnapshot();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $code = Artisan::call('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1', '--cover-groups' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code, $output);
        $this->assertMetric($output, 'unsupported_'.$table.'_rows', 1);
        $this->assertMetric($output, 'unsupported_charged_price_scope', 1);
        $this->assertStringNotContainsString('PRIVATE CONTRACT AMOUNT', $output);
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public static function unsupportedChargedTables(): array
    {
        return [['product_option_price_by_cigroup'], ['product_customergroup_price']];
    }

    #[DataProvider('referenceDisplayScopes')]
    public function test_reference_data_blocks_only_enabled_or_unverified_display_scopes_without_writing(int $rows, ?array $settings, int $failed, int $anchor, int $digital, int $unavailable): void
    {
        $this->source('product_anchor_price', ['product_id', 'price'], $rows ? [['product_id' => '1', 'price' => 'PRIVATE REFERENCE AMOUNT']] : []);
        if ($settings !== null) {
            $this->source('setting', ['store_id', 'key', 'value'], $settings);
        }
        $before = $this->databaseSnapshot();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $code = Artisan::call('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1']);
        $output = Artisan::output();

        $this->assertSame($failed, $code, $output);
        $this->assertMetric($output, 'reference_rows', $rows);
        $this->assertMetric($output, 'reference_display_unverified_rows', $rows);
        $this->assertMetric($output, 'module_anchor_price_status', $anchor);
        $this->assertMetric($output, 'module_digital_pricelist_status', $digital);
        $this->assertMetric($output, 'reference_display_flags_unavailable', $unavailable);
        $this->assertMetric($output, 'unsupported_active_reference_display', $failed);
        $this->assertStringNotContainsString('PRIVATE REFERENCE AMOUNT', $output);
        if (! $failed) {
            $this->assertStringContainsString('Reference-price display is outside this parity proof.', $output);
            $this->assertStringContainsString($rows ? 'both source display features are verified disabled' : 'No source anchor rows are present', $output);
        }
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public static function referenceDisplayScopes(): array
    {
        $setting = fn ($key, $value, $store = '0') => ['store_id' => $store, 'key' => 'module_'.$key.'_status', 'value' => $value];
        $disabled = [$setting('anchor_price', '0'), $setting('digital_pricelist', '0')];

        return [
            'disabled reference data' => [1, $disabled, 0, 0, 0, 0],
            'enabled anchor display' => [1, [$setting('anchor_price', '1'), $setting('digital_pricelist', '0')], 1, 1, 0, 0],
            'enabled digital price list' => [1, [$setting('anchor_price', '0'), $setting('digital_pricelist', '1')], 1, 0, 1, 0],
            'missing settings table' => [1, null, 1, -1, -1, 2],
            'missing digital status' => [1, [$setting('anchor_price', '0')], 1, 0, -1, 1],
            'another store cannot prove disabled' => [1, [$setting('anchor_price', '0', '1'), $setting('digital_pricelist', '0', '1')], 1, -1, -1, 2],
            'duplicate flag is ambiguous' => [1, [...$disabled, $setting('anchor_price', '0')], 1, -1, 0, 1],
            'invalid flag is unavailable' => [1, [$setting('anchor_price', 'unknown'), $setting('digital_pricelist', '0')], 1, -1, 0, 1],
            'no reference data does not require settings' => [0, null, 0, -1, -1, 2],
            'no reference data with enabled modules' => [0, [$setting('anchor_price', '1'), $setting('digital_pricelist', '1')], 0, 1, 1, 0],
        ];
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

    public function test_cover_groups_checks_every_usable_group_and_reports_unavailable_accounts_without_writes(): void
    {
        $groups = $this->coverageFixture();
        $unused = CustomerGroup::query()->create(['code' => 'audit-unused', 'name' => 'No usable account', 'is_active' => true]);
        $this->map('customer_group', 99, $unused->id);
        $resolver = $this->recordingResolver();
        $before = $this->databaseSnapshot();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $code = Artisan::call('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1', '--cover-groups' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertMetric($output, 'coverage_mapped_groups', 4);
        $this->assertMetric($output, 'coverage_groups_checked', 3);
        $this->assertMetric($output, 'coverage_groups_without_usable_accounts', 1);
        $this->assertMetric($output, 'coverage_group_price_groups_checked', 3);
        $this->assertMetric($output, 'coverage_special_groups_checked', 2);
        $this->assertMetric($output, 'coverage_quantity_groups_checked', 2);
        $this->assertMetric($output, 'coverage_scenarios_unchecked', 0);
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->databaseSnapshot());
        $future = array_filter($resolver->calls, fn ($call) => $call['group'] === $groups[2]->id);
        $expired = array_filter($resolver->calls, fn ($call) => $call['group'] === $groups[3]->id);
        foreach (['2030-04-10', '2030-04-20'] as $date) {
            foreach ([-1, 0, 1] as $offset) {
                $this->assertContains(CarbonImmutable::parse($date)->addSeconds($offset)->format('Y-m-d H:i:s'), array_column($future, 'at'));
            }
        }
        foreach (['2020-03-10', '2020-03-20'] as $date) {
            foreach ([-1, 0, 1] as $offset) {
                $this->assertContains(CarbonImmutable::parse($date)->addSeconds($offset)->format('Y-m-d H:i:s'), array_column($expired, 'at'));
            }
        }
        foreach ([6, 7, 8] as $quantity) {
            $this->assertNotEmpty(array_filter($expired, fn ($call) => $call['quantity'] === $quantity && $call['at'] === '2020-03-10 00:00:01'));
        }
        $this->assertLessThanOrEqual(4000, count($resolver->calls));
    }

    #[DataProvider('dateBoundaryFailures')]
    public function test_group_coverage_catches_runtime_failures_at_future_and_expired_date_boundaries(int $sourceGroup, string $at): void
    {
        $groups = $this->coverageFixture();
        $this->recordingResolver(fn ($call) => $call['group'] === $groups[$sourceGroup]->id && $call['at'] === $at);
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1'])->assertSuccessful();
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1', '--cover-groups' => true])
            ->expectsOutputToContain('coverage_effective_price_mismatch')->expectsOutputToContain('FAIL: pricing is not ready for activation')->assertFailed();
    }

    public static function dateBoundaryFailures(): array
    {
        return ['future_start_is_exclusive' => [2, '2030-04-10 00:00:00'], 'expired_end_is_exclusive' => [3, '2020-03-20 00:00:00']];
    }

    #[DataProvider('quantityBoundaryFailures')]
    public function test_group_coverage_catches_less_at_and_above_quantity_threshold_runtime_failures(int $quantity): void
    {
        $groups = $this->coverageFixture();
        $this->recordingResolver(fn ($call) => $call['group'] === $groups[3]->id && $call['quantity'] === $quantity && $call['at'] === '2020-03-10 00:00:01');
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1'])->assertSuccessful();
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1', '--cover-groups' => true])
            ->expectsOutputToContain('coverage_effective_price_mismatch')->assertFailed();
    }

    public static function quantityBoundaryFailures(): array
    {
        return ['less' => [6], 'at' => [7], 'above' => [8]];
    }

    public function test_coverage_limit_is_a_visible_failure_while_stored_rows_are_still_fully_audited(): void
    {
        $this->coverageFixture();
        $resolver = $this->recordingResolver();
        $command = new class extends HerreraPricingAudit
        {
            protected const MAX_EFFECTIVE_SCENARIOS = 12;
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $code = $command->run(new ArrayInput(['--source-connection' => self::SOURCE, '--samples' => '1', '--cover-groups' => true]), $output);
        $text = $output->fetch();
        $this->assertSame(1, $code, $text);
        $this->assertMetric($text, 'effective_scenarios_total', 12);
        $this->assertMetric($text, 'coverage_limit_exceeded', 1);
        $this->assertMetric($text, 'source_valid_entries', $this->catalog->entries()->count());
        $this->assertLessThanOrEqual(12, count($resolver->calls));
        $this->assertSame('draft', $this->catalog->fresh()->status);
    }

    public function test_groups_with_only_inactive_contracts_are_reported_without_fabricating_customers(): void
    {
        $group = $this->additionalGroup(2);
        B2BAccount::query()->where('customer_group_id', $group->id)->update(['contract_ends_at' => '2000-01-01']);
        $before = $this->databaseSnapshot();
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1', '--cover-groups' => true])
            ->expectsOutputToContain('coverage_groups_without_usable_accounts')->assertSuccessful();
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_coverage_does_not_let_the_first_special_hide_other_schedule_strata_in_the_same_group(): void
    {
        $groups = $this->coverageFixture();
        foreach ([3 => ['2020-05-10', '2020-05-20', '1'], 4 => ['2026-10-10', '2026-10-20', '2'], 5 => ['0000-00-00', '0000-00-00', '3']] as $id => [$start, $end, $product]) {
            $this->additionalPrice('special', ['product_special_id' => (string) $id, 'product_id' => $product, 'customer_group_id' => '2', 'priority' => '1', 'price' => '15.4321', 'date_start' => $start, 'date_end' => $end]);
        }
        $this->refreshSourceChecksum();
        $resolver = $this->recordingResolver();
        $code = Artisan::call('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1', '--cover-groups' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertMetric($output, 'coverage_special_rows_sampled', 5);
        $calls = array_filter($resolver->calls, fn ($call) => $call['group'] === $groups[2]->id);
        foreach (['2030-04-10 00:00:00', '2020-05-10 00:00:00', '2026-10-10 00:00:00', '2026-10-14 12:00:00'] as $time) {
            $this->assertContains($time, array_column($calls, 'at'));
        }
    }

    public function test_coverage_uses_actual_legacy_dates_when_native_import_normalizes_a_1970_end_date(): void
    {
        $this->coverageFixture();
        $this->additionalPrice('special', ['product_special_id' => '3', 'product_id' => '3', 'customer_group_id' => '2', 'priority' => '1', 'price' => '15.4321', 'date_start' => '0000-00-00', 'date_end' => '0000-00-00']);
        $this->additionalPrice('special', ['product_special_id' => '4', 'product_id' => '5', 'customer_group_id' => '2', 'priority' => '1', 'price' => '10.4321', 'date_start' => '0000-00-00', 'date_end' => '1970-01-01']);
        // Mirror the importer's existing normalization, so stored-row auditing alone cannot catch this semantic mismatch.
        $this->catalog->entries()->where('source_key', 'product_special:4')->update(['ends_at' => null]);
        $this->refreshSourceChecksum();
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1'])->assertSuccessful();
        $this->artisan('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1', '--cover-groups' => true])
            ->expectsOutputToContain('coverage_effective_price_mismatch')->assertFailed();
    }

    public function test_two_digit_quantity_boundary_matches_mysql_numeric_semantics_in_text_fixture_snapshots(): void
    {
        $groups = $this->coverageFixture(10);
        $resolver = $this->recordingResolver();
        $code = Artisan::call('herrera:audit-pricing', ['--source-connection' => self::SOURCE, '--samples' => '1', '--cover-groups' => true]);
        $this->assertSame(0, $code, Artisan::output());
        foreach ([9, 10, 11] as $quantity) {
            $this->assertNotEmpty(array_filter($resolver->calls, fn ($call) => $call['group'] === $groups[3]->id && $call['quantity'] === $quantity && $call['at'] === '2020-03-10 00:00:01'));
        }
    }

    private function coverageFixture(int $threshold = 7): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00'));
        $groups = [1 => CustomerGroup::query()->where('code', 'audit-group')->sole(), 2 => $this->additionalGroup(2), 3 => $this->additionalGroup(3)];
        foreach ([2, 3] as $sourceGroup) {
            $this->additionalPrice('group', ['product_id' => '2', 'group_id' => (string) $sourceGroup, 'category_id' => '0', 'price' => '40.1234']);
        }
        $this->additionalPrice('special', ['product_special_id' => '2', 'product_id' => '4', 'customer_group_id' => '2', 'priority' => '1', 'price' => '30.2345', 'date_start' => '2030-04-10', 'date_end' => '2030-04-20']);
        $this->additionalPrice('quantity', ['product_discount_id' => '2', 'product_id' => '2', 'customer_group_id' => '3', 'quantity' => (string) $threshold, 'priority' => '1', 'price' => '20.3456', 'date_start' => '2020-03-10', 'date_end' => '2020-03-20']);
        $this->refreshSourceChecksum();

        return $groups;
    }

    private function additionalGroup(int $sourceId): CustomerGroup
    {
        $group = CustomerGroup::query()->create(['code' => 'audit-group-'.$sourceId, 'name' => 'Audit group '.$sourceId, 'is_active' => true]);
        $user = User::factory()->create();
        $user->b2bAccount()->create(['company_name' => 'Pricing fixture', 'oib' => str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'status' => 'approved', 'customer_group_id' => $group->id]);
        $this->map('customer_group', $sourceId, $group->id);
        $this->map('customer', $sourceId, $user->id);
        DB::connection(self::SOURCE)->table('oc_customer')->insert(['customer_id' => (string) $sourceId, 'customer_group_id' => (string) $sourceId, 'status' => '1']);

        return $group;
    }

    private function additionalPrice(string $kind, array $row): void
    {
        $table = ['group' => 'product_price_by_cigroup', 'special' => 'product_special', 'quantity' => 'product_discount'][$kind];
        DB::connection(self::SOURCE)->table('oc_'.$table)->insert($row);
        $targetGroup = DB::table('herrera_import_maps')->where('entity', 'customer_group')->where('source_id', $row['group_id'] ?? $row['customer_group_id'])->value('target_id');
        $product = Product::query()->where('code', 'audit-product-'.$row['product_id'])->sole();
        $key = $table.':'.($row['product_special_id'] ?? $row['product_discount_id'] ?? $row['product_id'].':'.$row['group_id'].':0');
        $this->catalog->entries()->create(['source_key' => $key, 'product_id' => $product->id, 'kind' => $kind, 'customer_group_id' => $targetGroup,
            'minimum_quantity' => (int) ($row['quantity'] ?? 1), 'priority' => (int) ($row['priority'] ?? 0), 'price' => $row['price'], 'is_active' => true,
            'starts_at' => isset($row['date_start']) && ! str_starts_with($row['date_start'], '0000-') ? $row['date_start'].' 00:00:00' : null, 'ends_at' => isset($row['date_end']) && ! str_starts_with($row['date_end'], '0000-') ? $row['date_end'].' 00:00:00' : null,
            'payload' => ['opencart' => $row]]);
    }

    private function refreshSourceChecksum(): void
    {
        $digest = hash_init('sha256');
        foreach (['product', 'product_price_by_cigroup', 'product_price_by_customer_id', 'product_discount', 'product_special'] as $table) {
            $query = DB::connection(self::SOURCE)->table('oc_'.$table);
            foreach (array_slice(DB::connection(self::SOURCE)->getSchemaBuilder()->getColumnListing('oc_'.$table), 0, 3) as $column) {
                $query->orderBy($column);
            }
            foreach ($query->get() as $row) {
                hash_update($digest, json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
            }
        }
        $this->catalog->update(['source_checksum' => hash_final($digest), 'metadata' => ['import_complete' => true, 'import_expected_entries' => $this->catalog->entries()->count()]]);
    }

    private function recordingResolver(?\Closure $tamper = null): PriceCatalogResolver
    {
        $resolver = new class($tamper) extends PriceCatalogResolver
        {
            public array $calls = [];

            public function __construct(private readonly ?\Closure $tamper) {}

            public function resolve(Product $product, ?User $user, int $quantity = 1, ?float $fallback = null, ?PriceCatalog $catalog = null, Carbon|string|null $at = null): ?ResolvedB2BPrice
            {
                $call = ['group' => $user?->b2bAccount?->customer_group_id, 'product' => $product->id, 'quantity' => $quantity, 'at' => $at instanceof Carbon ? $at->format('Y-m-d H:i:s') : $at];
                $this->calls[] = $call;
                $price = parent::resolve($product, $user, $quantity, $fallback, $catalog, $at);
                if ($price && $this->tamper && ($this->tamper)($call)) {
                    return new ResolvedB2BPrice($price->id, $price->price + 1, $price->source_type, $price->customer_group_id, catalog_id: $price->catalog_id);
                }

                return $price;
            }
        };
        $this->app->instance(PriceCatalogResolver::class, $resolver);

        return $resolver;
    }

    private function assertMetric(string $output, string $metric, int $value): void
    {
        $this->assertMatchesRegularExpression('/'.preg_quote($metric, '/').'\s+\|\s+'.preg_quote((string) $value, '/').'\s*\|/', $output);
    }

    private function databaseSnapshot(): array
    {
        $result = [];
        foreach (['users', 'b2b_accounts', 'customer_groups', 'catalog_price_catalogs', 'catalog_price_entries', 'catalog_price_catalog_state', 'catalog_price_catalog_audits'] as $table) {
            $result['target_'.$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach (['product', 'product_price_by_cigroup', 'product_price_by_customer_id', 'product_discount', 'product_special', 'customer',
            'product_option_price_by_cigroup', 'product_customergroup_price', 'product_anchor_price', 'setting'] as $table) {
            $source = DB::connection(self::SOURCE);
            if ($source->getSchemaBuilder()->hasTable('oc_'.$table)) {
                $result['source_'.$table] = $source->table('oc_'.$table)->orderBy($source->getSchemaBuilder()->getColumnListing('oc_'.$table)[0])->get()->toJson();
            }
        }

        return $result;
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
