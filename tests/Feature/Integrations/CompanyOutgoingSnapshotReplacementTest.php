<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductClearance;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\User\CustomerGroup;
use App\Services\Catalog\Clearance\ClearanceService;
use App\Services\Catalog\Clearance\CompanyOutgoingImport;
use App\Services\Catalog\Clearance\CompanySalesClearanceSync;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CompanyOutgoingSnapshotReplacementTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('previousSnapshotAges')]
    public function test_replacement_invalidates_omitted_observed_evidence_without_creating_discounts(int $previousAge): void
    {
        $included = $this->product('INCLUDED');
        $omitted = $this->product('OMITTED');
        $evidence = $this->observe($omitted, ['snapshot_date' => today()->subDays($previousAge)]);
        $file = $this->report([['INCLUDED', today()->toDateString()]]);

        try {
            $import = app(CompanyOutgoingImport::class);
            $this->assertSame(0, $import->run($file)['invalidated']);
            $this->assertTrue($evidence->fresh()->confirmed_company_scope);
            $this->assertDatabaseCount('product_clearances', 1);

            $this->assertSame(1, $import->run($file, true, true)['invalidated']);
            $this->assertFalse($evidence->fresh()->confirmed_company_scope);
            $this->assertSame('observed', $evidence->fresh()->status);
            $this->assertSame(str_repeat('a', 64), $evidence->fresh()->source_fingerprint);
            $this->assertTrue(ProductClearance::query()->where('product_id', $included->id)->sole()->confirmed_company_scope);

            $category = Category::query()->create(['scope' => 'catalog', 'code' => 'clearance']);
            $service = app(ClearanceService::class);
            $service->saveSettings(['enabled' => true, 'category_id' => $category->id]);
            $preview = $service->run();
            $this->assertSame(1, $preview['eligible']);
            $this->assertSame(1, $preview['stale_or_unproven']);
            $this->assertDatabaseCount('catalog_product_group_prices', 0);
            $this->assertNull(data_get($omitted->fresh()->payload, 'clearance'));
        } finally {
            unlink($file);
        }
    }

    public static function previousSnapshotAges(): array
    {
        return ['same day' => [0], 'previous day' => [1]];
    }

    public function test_replacement_preserves_existing_clearance_statuses_prices_and_product_flags(): void
    {
        $active = $this->product('ACTIVE', ['payload' => ['clearance' => ['status' => 'active']]]);
        $soldOut = $this->product('SOLD-OUT', ['is_active' => false, 'payload' => ['clearance' => ['status' => 'sold_out']]]);
        $manual = $this->product('MANUAL', ['payload' => ['clearance' => ['status' => 'manual_override']]]);
        $activeEvidence = $this->observe($active, ['status' => 'active']);
        $soldEvidence = $this->observe($soldOut, ['status' => 'sold_out']);
        $manualEvidence = $this->observe($manual, ['status' => 'manual_override']);
        $group = CustomerGroup::query()->create(['code' => 'B2B60', 'name' => 'B2B60', 'is_active' => true]);
        $price = ProductGroupPrice::query()->create(['product_id' => $active->id, 'customer_group_id' => $group->id, 'minimum_quantity' => 1, 'price' => 40, 'currency_code' => 'EUR', 'is_active' => true, 'payload' => ['source' => 'automatic_clearance']]);
        $beforePrice = $price->fresh()->toArray();
        $beforeProducts = [$active->toArray(), $soldOut->toArray(), $manual->toArray()];
        $beforeSold = $soldEvidence->toArray();
        $beforeManual = $manualEvidence->toArray();
        $file = $this->report([['ACTIVE', today()->toDateString()]]);

        try {
            $this->assertSame(0, app(CompanyOutgoingImport::class)->run($file, true, true)['invalidated']);
            $this->assertSame('active', $activeEvidence->fresh()->status);
            $this->assertTrue($activeEvidence->fresh()->confirmed_company_scope);
            $this->assertSame($beforeSold, $soldEvidence->fresh()->toArray());
            $this->assertSame($beforeManual, $manualEvidence->fresh()->toArray());
            $this->assertSame($beforePrice, $price->fresh()->toArray());
            $this->assertSame($beforeProducts, [$active->fresh()->toArray(), $soldOut->fresh()->toArray(), $manual->fresh()->toArray()]);
            $this->assertDatabaseCount('catalog_product_group_prices', 1);
        } finally {
            unlink($file);
        }
    }

    public function test_older_company_snapshot_cannot_replace_proof_for_an_omitted_product(): void
    {
        $included = $this->product('OLDER');
        $omitted = $this->product('CURRENT');
        $includedEvidence = $this->observe($included, ['snapshot_date' => today()->subDay()]);
        $omittedEvidence = $this->observe($omitted);
        $before = [$includedEvidence->toArray(), $omittedEvidence->toArray()];
        $file = $this->report([['OLDER', today()->subDay()->toDateString()]]);

        try {
            $this->expectException(RuntimeException::class);
            app(CompanyOutgoingImport::class)->run($file, true, true);
        } finally {
            $this->assertSame($before, [$includedEvidence->fresh()->toArray(), $omittedEvidence->fresh()->toArray()]);
            unlink($file);
        }
    }

    public function test_later_row_failure_rolls_back_updated_evidence_and_preserves_omitted_proof(): void
    {
        $first = $this->product('FIRST');
        $newer = $this->product('NEWER');
        $omitted = $this->product('OMITTED');
        $firstEvidence = $this->observe($first, ['snapshot_date' => today()->subDay()]);
        $newerEvidence = $this->observe($newer, ['confirmed_company_scope' => false]);
        $omittedEvidence = $this->observe($omitted, ['snapshot_date' => today()->subDay()]);
        $before = [$firstEvidence->toArray(), $newerEvidence->toArray(), $omittedEvidence->toArray()];
        $file = $this->report([['FIRST', today()->subDay()->toDateString()], ['NEWER', today()->subDay()->toDateString()]]);

        try {
            $this->expectException(RuntimeException::class);
            app(CompanyOutgoingImport::class)->run($file, true, true);
        } finally {
            $this->assertSame($before, [$firstEvidence->fresh()->toArray(), $newerEvidence->fresh()->toArray(), $omittedEvidence->fresh()->toArray()]);
            $this->assertDatabaseCount('catalog_product_group_prices', 0);
            unlink($file);
        }
    }

    public function test_mixed_snapshot_dates_are_rejected_without_invalidating_existing_proof(): void
    {
        $this->product('FIRST');
        $this->product('SECOND');
        $evidence = $this->observe($this->product('OMITTED'));
        $file = $this->report([['FIRST', today()->toDateString()], ['SECOND', today()->subDay()->toDateString()]]);

        try {
            $this->expectException(RuntimeException::class);
            app(CompanyOutgoingImport::class)->run($file, true, true);
        } finally {
            $this->assertTrue($evidence->fresh()->confirmed_company_scope);
            $this->assertDatabaseCount('product_clearances', 1);
            unlink($file);
        }
    }

    public function test_manual_replacement_invalidates_omitted_api_observation_even_when_its_sync_state_is_current(): void
    {
        $this->product('INCLUDED');
        $evidence = $this->observe($this->product('API-OMITTED'), ['source' => CompanySalesClearanceSync::SOURCE]);
        app(SystemSettingsService::class)->put(CompanySalesClearanceSync::STATE_KEY, [
            'status' => 'completed', 'as_of' => today()->toDateString(), 'fingerprint' => str_repeat('a', 64),
        ]);
        $file = $this->report([['INCLUDED', today()->toDateString()]]);

        try {
            $this->assertSame(1, app(CompanyOutgoingImport::class)->run($file, true, true)['invalidated']);
            $this->assertFalse($evidence->fresh()->confirmed_company_scope);
            $this->assertSame(CompanySalesClearanceSync::SOURCE, $evidence->fresh()->source);
            $this->assertSame('observed', $evidence->fresh()->status);
            $this->assertSame(str_repeat('a', 64), $evidence->fresh()->source_fingerprint);
            $category = Category::query()->create(['scope' => 'catalog', 'code' => 'clearance']);
            $service = app(ClearanceService::class);
            $service->saveSettings(['enabled' => true, 'category_id' => $category->id]);
            $this->assertSame(1, $service->run()['eligible']);
            $this->assertDatabaseCount('catalog_product_group_prices', 0);
        } finally {
            unlink($file);
        }
    }

    public function test_newer_api_company_snapshot_blocks_an_older_manual_replacement(): void
    {
        $included = $this->observe($this->product('OLDER'), ['snapshot_date' => today()->subDay()]);
        $api = $this->observe($this->product('API-CURRENT'), ['source' => CompanySalesClearanceSync::SOURCE]);
        $before = [$included->toArray(), $api->toArray()];
        $file = $this->report([['OLDER', today()->subDay()->toDateString()]]);

        try {
            $this->expectException(RuntimeException::class);
            app(CompanyOutgoingImport::class)->run($file, true, true);
        } finally {
            $this->assertSame($before, [$included->fresh()->toArray(), $api->fresh()->toArray()]);
            $this->assertDatabaseCount('catalog_product_group_prices', 0);
            unlink($file);
        }
    }

    private function product(string $code, array $extra = []): Product
    {
        return Product::query()->create(array_replace(['code' => $code, 'sku' => $code, 'base_price' => 100, 'is_active' => true, 'stock_qty' => 10, 'minimum_order_quantity' => 1, 'order_quantity_step' => 1, 'payload' => ['eracuni' => ['productCode' => $code]]], $extra))->fresh();
    }

    private function observe(Product $product, array $extra = []): ProductClearance
    {
        return ProductClearance::query()->create(array_replace(['product_id' => $product->id, 'source' => 'eracuni_company_export', 'source_fingerprint' => str_repeat('a', 64), 'coverage_from' => today()->subDays(120), 'snapshot_date' => today(), 'last_outgoing_at' => today()->subDays(100), 'confirmed_company_scope' => true], $extra))->fresh();
    }

    private function report(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'clearance-replacement-');
        $stream = fopen($file, 'w');
        fputcsv($stream, ['product_code', 'last_outgoing_at', 'coverage_from', 'as_of'], ',', '"', '');
        foreach ($rows as [$code, $asOf]) {
            fputcsv($stream, [$code, today()->subDays(100)->toDateString(), today()->subDays(120)->toDateString(), $asOf], ',', '"', '');
        }
        fclose($stream);

        return $file;
    }
}
