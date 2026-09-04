<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductPriceHistory;
use App\Models\Settings\Local\TaxRate;
use App\Services\Pricing\ErpCashPricingService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpCashPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_erp_cash_rebate_is_stored_as_regular_pricing_and_not_as_a_promotion(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);

        $product = Product::query()->create([
            'code' => 'ERP-CASH-PRICE',
            'sku' => 'ERP-CASH-PRICE',
            'is_active' => true,
            'base_price' => 0,
            'stock_qty' => 5,
        ]);

        $attributes = app(ErpCashPricingService::class)->attributesForProduct(
            $product,
            grossListPrice: 100,
            cashDiscountPercent: 10,
        );
        $product->update($attributes);
        $product->refresh();

        $this->assertSame('100.0000', $product->erp_gross_list_price);
        $this->assertSame('10.0000', $product->erp_cash_discount_percent);
        $this->assertSame('90.0000', $product->erp_cash_selling_price);
        $this->assertSame('90.00', $product->base_price);
        $this->assertSame(0, CatalogAction::query()->count());

        $presentation = app(ProductPricePresentationService::class)->forProduct($product);

        $this->assertSame(90.0, $presentation['current_gross']);
        $this->assertSame(90.0, $presentation['base_gross']);
        $this->assertNull($presentation['old_gross']);
        $this->assertNull($presentation['discount_percent']);
        $this->assertNull($presentation['lowest_30_days_gross']);
        $this->assertFalse($presentation['has_discount']);
        $this->assertFalse($presentation['has_promotional_discount']);
        $this->assertSame('base', $presentation['price_source']);
    }

    public function test_cash_price_changes_continue_to_write_canonical_base_price_history(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);

        $product = Product::query()->create([
            'code' => 'ERP-CASH-HISTORY',
            'sku' => 'ERP-CASH-HISTORY',
            'is_active' => true,
            'base_price' => 90,
            'erp_gross_list_price' => 100,
            'erp_cash_discount_percent' => 10,
            'erp_cash_selling_price' => 90,
            'stock_qty' => 5,
        ]);

        $product->update(app(ErpCashPricingService::class)->attributesForProduct(
            $product,
            grossListPrice: 100,
            cashDiscountPercent: 20,
        ));

        $history = ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->where('price_type', 'base')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(90.0, (float) $history->old_price);
        $this->assertSame(80.0, (float) $history->new_price);
        $this->assertSame(2, ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->where('price_type', 'base')
            ->count());
    }

    public function test_erp_gross_cash_price_respects_the_existing_net_base_price_setting(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $taxRate = TaxRate::query()->create([
            'code' => 'pdv25-erp-price',
            'name' => 'PDV 25%',
            'rate_type' => 'percent',
            'rate' => 25,
            'priority' => 1,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = Product::query()->create([
            'code' => 'ERP-CASH-NET-STORAGE',
            'sku' => 'ERP-CASH-NET-STORAGE',
            'is_active' => true,
            'tax_rate_id' => $taxRate->id,
            'base_price' => 0,
            'stock_qty' => 5,
        ]);

        $product->update(app(ErpCashPricingService::class)->attributesForProduct(
            $product,
            grossListPrice: 100,
            cashDiscountPercent: 10,
        ));
        $product->refresh();

        $this->assertSame('100.0000', $product->erp_gross_list_price);
        $this->assertSame('90.0000', $product->erp_cash_selling_price);
        $this->assertSame('72.00', $product->base_price);

        $presentation = app(ProductPricePresentationService::class)->forProduct($product);

        $this->assertSame(90.0, $presentation['current_gross']);
        $this->assertFalse($presentation['has_promotional_discount']);
    }

    public function test_a_real_catalog_action_remains_the_only_source_of_promotional_presentation(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);

        $product = Product::query()->create([
            'code' => 'ERP-CASH-ACTION',
            'sku' => 'ERP-CASH-ACTION',
            'is_active' => true,
            'base_price' => 90,
            'erp_gross_list_price' => 100,
            'erp_cash_discount_percent' => 10,
            'erp_cash_selling_price' => 90,
            'stock_qty' => 5,
        ]);
        $action = CatalogAction::query()->create([
            'code' => 'ERP-REAL-PROMO',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $action->targets()->create([
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'target_id' => $product->id,
        ]);

        $presentation = app(ProductPricePresentationService::class)->forProduct($product);

        $this->assertSame(81.0, $presentation['current_gross']);
        $this->assertSame(90.0, $presentation['old_gross']);
        $this->assertSame(10, $presentation['discount_percent']);
        $this->assertTrue($presentation['has_discount']);
        $this->assertTrue($presentation['has_promotional_discount']);
        $this->assertSame('action', $presentation['price_source']);
        $this->assertNotNull($presentation['lowest_30_days_gross']);
    }
}
