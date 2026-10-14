<?php

namespace Tests\Feature\Import;

use App\Services\Import\HerreraBaselineConflictRevalidationService;
use App\Services\Import\HerreraFinalSyncOwnership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HerreraBaselineConflictRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'herrera-fresh-conflict-');
        config(['database.connections.herrera_revalidation_source' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('herrera_revalidation_source');
        Schema::connection('herrera_revalidation_source')->create('oc_product', function (Blueprint $table): void {
            foreach (['product_id', 'price', 'quantity', 'suplierqty', 'status'] as $field) {
                $table->text($field);
            }
        });
        $this->source()->table('oc_product')->insert(['product_id' => '30', 'price' => '100.1234', 'quantity' => '12', 'suplierqty' => '5', 'status' => '1']);
        $this->productId = DB::table('products')->insertGetId(['code' => 'herrera-oc-product-30', 'base_price' => '100.1234', 'stock_qty' => 12,
            'supplier_stock_qty' => 5, 'is_active' => true, 'payload' => json_encode(['opencart' => ['product_id' => '30']]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => 'product', 'source_id' => '30',
            'target_id' => $this->productId, 'checksum' => $this->sourceChecksum(), 'created_at' => now(), 'updated_at' => now()]);
        $this->remember();
    }

    protected function tearDown(): void
    {
        DB::disconnect('herrera_revalidation_source');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_fresh_snapshot_matching_native_operational_values_resolves_old_conflict_without_mutation(): void
    {
        $issue = $this->issue(['base_price', 'stock_qty', 'supplier_stock_qty', 'is_active']);
        DB::table('herrera_import_issues')->insert($issue + ['created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('herrera_import_issues')->get()->toJson();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $plan = $this->plan([$issue]);
        $this->assertSame(1, $plan['resolved']);
        $this->assertSame(0, $plan['unresolved']);
        $this->assertSame(1, $plan['total']);
        $this->assertSame('resolved_fresh_operational_state', $plan['details'][0]['reason']);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $sql);
        }
        $this->assertSame($before, DB::table('herrera_import_issues')->get()->toJson());
    }

    public function test_replaying_old_source_quantity_does_not_resolve_native_stock_difference(): void
    {
        $this->source()->table('oc_product')->update(['quantity' => '10']);
        $this->refreshMap();
        $this->remember();
        $plan = $this->plan();
        $this->assertSame(0, $plan['resolved']);
        $this->assertSame(1, $plan['unresolved']);
        $this->assertSame('fresh_operational_state_mismatch', $plan['details'][0]['reason']);
        $this->assertDatabaseHas('products', ['id' => $this->productId, 'stock_qty' => 12]);
    }

    public function test_stale_snapshot_baseline_cannot_prove_fresh_merge(): void
    {
        $this->remember('previous-frozen-snapshot');
        $plan = $this->plan();
        $this->assertSame(1, $plan['unresolved']);
        $this->assertSame('fresh_baseline_missing_or_stale', $plan['details'][0]['reason']);
    }

    public function test_fresh_source_row_changed_after_merge_cannot_use_old_mapping_fingerprint(): void
    {
        $this->source()->table('oc_product')->update(['price' => '100.1235']);
        $plan = $this->plan();
        $this->assertSame(1, $plan['unresolved']);
        $this->assertSame('fresh_source_map_checksum_mismatch', $plan['details'][0]['reason']);
    }

    public function test_two_source_products_mapped_to_one_target_are_ambiguous(): void
    {
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => 'product', 'source_id' => '31',
            'target_id' => $this->productId, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        $plan = $this->plan();
        $this->assertSame(1, $plan['unresolved']);
        $this->assertSame('missing_or_ambiguous_source_mapping', $plan['details'][0]['reason']);
    }

    public function test_native_edit_after_fresh_merge_and_inconsistent_baseline_both_remain_unresolved(): void
    {
        DB::table('products')->where('id', $this->productId)->update(['stock_qty' => 13]);
        $this->assertSame('fresh_operational_state_mismatch', $this->plan()['details'][0]['reason']);
        DB::table('products')->where('id', $this->productId)->update(['stock_qty' => 12]);
        (new HerreraFinalSyncOwnership($this->sourceFile, fn () => null))->rememberFields('product', '30', ['stock_qty' => 11]);
        $this->assertSame('fresh_operational_state_mismatch', $this->plan()['details'][0]['reason']);
    }

    public function test_non_operational_fields_and_other_issue_types_always_require_review(): void
    {
        $issues = [$this->issue(['stock_qty', 'sku']), $this->issue([]),
            array_replace($this->issue(), ['entity' => 'customer']),
            array_replace($this->issue(), ['code' => 'final_sync_native_edge_removed']),
            array_replace($this->issue(), ['context' => '"malformed scalar context"'])];
        $plan = $this->plan($issues);
        $this->assertSame(5, $plan['total']);
        $this->assertSame(0, $plan['resolved']);
        $this->assertSame(5, $plan['unresolved']);
    }

    public function test_decimal_comparison_preserves_four_places_and_all_three_values_must_match(): void
    {
        $this->source()->table('oc_product')->update(['price' => '100.1235']);
        $this->refreshMap();
        $this->remember();
        $this->assertSame(1, $this->plan([$this->issue(['base_price'])])['unresolved']);
        DB::table('products')->where('id', $this->productId)->update(['base_price' => '100.1235']);
        $this->assertSame(1, $this->plan([$this->issue(['base_price'])])['resolved']);
    }

    public function test_mismatched_native_source_identity_missing_target_and_duplicate_source_rows_remain_unresolved(): void
    {
        DB::table('products')->where('id', $this->productId)->update(['payload' => '{"opencart":{"product_id":99}}']);
        $this->assertSame('target_identity_mismatch', $this->plan()['details'][0]['reason']);
        DB::table('products')->where('id', $this->productId)->update(['payload' => '{"opencart":{"product_id":30}}']);
        $row = (array) $this->source()->table('oc_product')->first();
        $this->source()->table('oc_product')->insert($row);
        $this->assertSame('missing_or_ambiguous_source_product', $this->plan()['details'][0]['reason']);
        $this->source()->table('oc_product')->delete();
        $this->source()->table('oc_product')->insert($row);
        DB::table('herrera_import_maps')->where('entity', 'product')->update(['target_id' => $this->productId + 99]);
        $this->assertSame('missing_native_product', $this->plan()['details'][0]['reason']);
    }

    private function plan(?array $issues = null): array
    {
        return app(HerreraBaselineConflictRevalidationService::class)->plan($issues ?? [$this->issue()], $this->source());
    }

    private function issue(array $fields = ['stock_qty']): array
    {
        return ['entity' => 'product', 'source_id' => '30', 'code' => 'final_sync_field_conflict',
            'context' => json_encode(['fields' => $fields, 'snapshot' => 'original-frozen-snapshot'])];
    }

    private function remember(?string $snapshot = null): void
    {
        $source = $this->source()->table('oc_product')->first();
        (new HerreraFinalSyncOwnership($snapshot ?? $this->sourceFile, fn () => null))->rememberFields('product', '30',
            ['base_price' => $source->price, 'stock_qty' => $source->quantity, 'supplier_stock_qty' => (int) $source->suplierqty, 'is_active' => (bool) $source->status]);
    }

    private function refreshMap(): void
    {
        DB::table('herrera_import_maps')->where('entity', 'product')->where('source_id', '30')->update(['checksum' => $this->sourceChecksum()]);
    }

    private function sourceChecksum(): string
    {
        return hash('sha256', json_encode((array) $this->source()->table('oc_product')->first(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function source(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection('herrera_revalidation_source');
    }
}
