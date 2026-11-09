<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductClearance;
use App\Models\Catalog\Product\ProductPriceHistory;
use App\Services\Catalog\Clearance\ClearanceService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearancePriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
    }

    public function test_activation_records_the_actual_clearance_price_and_preserves_full_prior_price(): void
    {
        [$product] = $this->activate();
        $history = ProductPriceHistory::query()->where('price_type', 'clearance')->sole();
        $this->assertEquals(100, $history->old_price);
        $this->assertEquals(40, $history->new_price);
        $this->assertSame('activated', $history->payload['event']);
        $price = app(ProductPricePresentationService::class)->forProduct($product->fresh());
        $this->assertEquals(40, $price['current_net']);
        $this->assertEquals(100, $price['display_lowest_30_days']);
    }

    public function test_percentage_change_records_history_and_shows_40_as_prior_price_for_new_20_price(): void
    {
        [$product, $service] = $this->activate();
        $this->travel(10)->days();
        $service->saveSettings(['discount_percent' => 80]);
        $latest = ProductPriceHistory::query()->where('price_type', 'clearance')->latest('id')->firstOrFail();
        $this->assertEquals(40, $latest->old_price);
        $this->assertEquals(20, $latest->new_price);
        $price = app(ProductPricePresentationService::class)->forProduct($product->fresh());
        $this->assertEquals(20, $price['current_net']);
        $this->assertEquals(40, $price['display_lowest_30_days']);
        $service->saveSettings(['discount_percent' => 80]);
        $this->assertSame(2, ProductPriceHistory::query()->where('price_type', 'clearance')->count());

        $this->travel(1)->days();
        $service->saveSettings(['discount_percent' => 40]);
        $price = app(ProductPricePresentationService::class)->forProduct($product->fresh());
        $this->assertEquals(60, $price['current_net']);
        $this->assertEquals(20, $price['display_lowest_30_days']);
    }

    public function test_existing_clearance_without_new_history_still_records_its_actual_previous_price(): void
    {
        [$product, $service] = $this->activate();
        ProductPriceHistory::query()->where('price_type', 'clearance')->delete();
        $this->travel(1)->days();
        $service->saveSettings(['discount_percent' => 80]);
        $price = app(ProductPricePresentationService::class)->forProduct($product->fresh());
        $this->assertEquals(20, $price['current_net']);
        $this->assertEquals(40, $price['display_lowest_30_days']);
        $this->assertSame(1, ProductPriceHistory::query()->where('price_type', 'clearance')->count());
    }

    public function test_prices_outside_the_30_day_window_are_not_reintroduced(): void
    {
        [$product, $service] = $this->activate();
        ProductPriceHistory::query()->create([
            'product_id' => $product->id, 'price_type' => 'clearance', 'source' => 'automatic_clearance',
            'old_price' => 5, 'new_price' => 10, 'effective_at' => now()->subDays(31),
        ]);
        $this->travel(1)->days();
        $service->saveSettings(['discount_percent' => 80]);
        $this->assertEquals(40, app(ProductPricePresentationService::class)->forProduct($product->fresh())['display_lowest_30_days']);
    }

    private function activate(): array
    {
        $product = Product::query()->create(['code' => 'HISTORY', 'sku' => 'HISTORY', 'base_price' => 100, 'stock_qty' => 5, 'is_active' => true]);
        ProductClearance::query()->create([
            'product_id' => $product->id, 'source' => 'eracuni_company_export', 'source_fingerprint' => str_repeat('f', 64),
            'coverage_from' => today()->subDays(100), 'snapshot_date' => today(), 'last_outgoing_at' => null,
            'confirmed_company_scope' => true,
        ]);
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'clearance']);
        $service = app(ClearanceService::class);
        $service->saveSettings(['enabled' => true, 'category_id' => $category->id]);
        $this->assertSame(1, $service->run(true)['activated']);

        return [$product, $service];
    }
}
