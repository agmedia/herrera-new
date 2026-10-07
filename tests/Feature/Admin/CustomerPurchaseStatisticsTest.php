<?php

namespace Tests\Feature\Admin;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Services\Analytics\CustomerPurchaseStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CustomerPurchaseStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_separates_currencies_and_excludes_cancelled_orders_while_counting_guests(): void
    {
        $buyer = User::factory()->create();
        $paid = $this->createStatus('paid', ['is_paid' => true]);
        $cancelled = $this->createStatus('cancelled', ['is_cancelled' => true]);
        $importedCancelled = $this->createStatus('herrera-oc-status-7', ['name' => 'Otkazano']);

        $this->order($buyer, ['grand_total' => 125, 'tax_total' => 25, 'status_id' => $paid->id]);
        $this->order($buyer, ['grand_total' => 75, 'tax_total' => 15]);
        $this->order(null, ['grand_total' => 40, 'customer_email' => ' Guest@example.test ']);
        $this->order(null, ['grand_total' => 60, 'customer_email' => 'guest@example.test']);
        $this->order(null, ['grand_total' => 10, 'customer_email' => '']);
        $this->order(null, ['grand_total' => 20, 'customer_email' => '']);
        $this->order($buyer, ['grand_total' => 900, 'currency_code' => 'USD']);
        $this->order($buyer, ['grand_total' => 999, 'status_id' => $cancelled->id]);
        $this->order($buyer, ['grand_total' => 888, 'status_id' => $importedCancelled->id]);
        $this->order($buyer, ['grand_total' => 777, 'placed_at' => '2025-12-31 23:59:59']);

        $statistics = app(CustomerPurchaseStatistics::class);
        $filters = ['currency' => 'EUR', 'dateFrom' => '2026-01-01', 'dateTo' => '2026-12-31'];
        $summary = $statistics->summary($filters);

        $this->assertSame(6, $summary['orders']);
        $this->assertSame(330.0, $summary['order_value']);
        $this->assertSame(125.0, $summary['paid_value']);
        $this->assertSame(55.0, $summary['average_order']);
        $this->assertSame(4, $summary['customers']);
        $this->assertSame(2, $summary['repeat_customers']);
        $this->assertSame(50.0, $summary['repeat_rate']);
        $this->assertSame(2, $summary['cancelled_orders']);
        $this->assertSame(6, $summary['items_sold']);

        $customers = $statistics->customers($filters)->get();
        $this->assertCount(4, $customers);
        $this->assertEquals(200, $customers->firstWhere('user_id', $buyer->id)->order_value);
        $this->assertEquals(100, $customers->firstWhere('guest_email', 'guest@example.test')->order_value);
    }

    public function test_product_ranking_preserves_native_and_imported_net_amounts_and_does_not_merge_unlinked_products(): void
    {
        $buyer = User::factory()->create();
        $first = $this->order($buyer, ['grand_total' => 125, 'tax_total' => 25]);
        $imported = $this->order($buyer, ['source' => 'opencart_import', 'grand_total' => 62.50, 'tax_total' => 12.50]);
        $other = $this->order(null, ['grand_total' => 80]);
        $usd = $this->order($buyer, ['currency_code' => 'USD', 'grand_total' => 999]);
        $cancelled = $this->order($buyer, ['status_id' => $this->createStatus('cancelled', ['is_cancelled' => true])->id]);
        $this->item($first, 'GLOVE-A', 2, 100, 25);
        $this->item($imported, 'GLOVE-A', 1, 50, 12.50);
        $this->item($other, 'GLOVE-B', 4, 80, 0);
        $this->item($usd, 'GLOVE-A', 100, 999, 0);
        $this->item($cancelled, 'GLOVE-A', 200, 999, 0);

        $statistics = app(CustomerPurchaseStatistics::class);
        $products = $statistics->products(['currency' => 'EUR'])->get();

        $this->assertCount(2, $products);
        $this->assertSame('GLOVE-B', $products[0]->sku);
        $this->assertEquals(3, $products->firstWhere('sku', 'GLOVE-A')->quantity);
        $this->assertEquals(150, $products->firstWhere('sku', 'GLOVE-A')->net_value);
        $this->assertEquals(2, $products->firstWhere('sku', 'GLOVE-A')->order_count);

        $customerProducts = $statistics->products(['currency' => 'EUR', 'user_id' => $buyer->id])->get();
        $this->assertCount(1, $customerProducts);
        $this->assertSame('GLOVE-A', $customerProducts->first()->sku);
    }

    public function test_date_filter_uses_placed_at_and_falls_back_to_creation_time_with_inclusive_end_date(): void
    {
        $buyer = User::factory()->create();
        $this->order($buyer, ['grand_total' => 10, 'placed_at' => '2026-02-01 00:00:00', 'created_at' => '2026-04-01 12:00:00']);
        $this->order($buyer, ['grand_total' => 20, 'placed_at' => '2026-02-28 23:59:59']);
        $this->order($buyer, ['grand_total' => 30, 'placed_at' => null, 'created_at' => '2026-02-15 12:00:00']);
        $this->order($buyer, ['grand_total' => 40, 'placed_at' => '2026-03-01 00:00:00']);

        $statistics = app(CustomerPurchaseStatistics::class);
        $filters = ['currency' => 'EUR', 'dateFrom' => '2026-02-01', 'dateTo' => '2026-02-28'];
        $summary = $statistics->summary($filters);
        $trend = $statistics->monthlyTrend($filters);

        $this->assertSame(3, $summary['orders']);
        $this->assertSame(60.0, $summary['order_value']);
        $this->assertCount(1, $trend);
        $this->assertSame('2026-02', $trend->first()->month);
        $this->assertEquals(60, $trend->first()->order_value);
    }

    public function test_variants_of_one_product_have_separate_counts_and_corresponding_skus(): void
    {
        $buyer = User::factory()->create();
        $order = $this->order($buyer);
        $product = Product::query()->create(['code' => 'SHIRT', 'sku' => 'SHIRT', 'base_price' => 10, 'is_active' => true]);
        $option = Option::query()->create(['code' => 'size', 'type' => 'select', 'is_active' => true]);
        foreach (['S' => 2, 'XL' => 5] as $size => $quantity) {
            $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => $size, 'is_active' => true]);
            $variant = ProductOptionValue::query()->create(['product_id' => $product->id, 'option_value_id' => $value->id, 'sku' => 'SHIRT-'.$size, 'combination_hash' => sha1($size)]);
            $order->items()->create(['product_id' => $product->id, 'product_option_value_id' => $variant->id, 'sku' => $variant->sku, 'name' => 'Shirt', 'quantity' => $quantity, 'line_total' => 10 * $quantity]);
        }

        $products = app(CustomerPurchaseStatistics::class)->products(['currency' => 'EUR'])->get();
        $this->assertCount(2, $products);
        $this->assertSame('SHIRT-XL', $products[0]->sku);
        $this->assertEquals(5, $products[0]->quantity);
        $this->assertSame('SHIRT-S', $products[1]->sku);
        $this->assertEquals(2, $products[1]->quantity);
    }

    public function test_admin_can_open_customer_statistics_and_customer_profile_without_combining_currencies(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->create(['name' => 'Analytics Buyer']);
        $first = $this->order($buyer, ['grand_total' => 125, 'tax_total' => 25]);
        $this->item($first, 'ARTICLE-ONE', 2, 100, 25);
        $this->order($buyer, ['grand_total' => 900, 'currency_code' => 'USD']);

        $this->actingAs($admin)->get(route('admin.users.statistics', ['user_id' => $buyer->id, 'dateFrom' => '', 'dateTo' => '', 'currency' => 'EUR']))
            ->assertOk()
            ->assertSee('Statistika kupaca i artikala')
            ->assertSee('Analytics Buyer')
            ->assertSee('ARTICLE-ONE')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['order_value'] === 125.0)
            ->assertViewHas('products', fn ($products): bool => (float) $products->first()->net_value === 100.0);

        $this->actingAs($admin)->get(route('admin.users.show', $buyer))
            ->assertOk()
            ->assertSee('Statistika kupnje')
            ->assertSee('data-customer-purchase-currency="EUR"', false)
            ->assertSee('data-customer-purchase-currency="USD"', false);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.users.statistics'), false);
    }

    public function test_statistics_require_both_customer_and_order_view_abilities(): void
    {
        foreach ([['users.list.view'], ['sales.orders.view'], ['users.list.view', 'sales.orders.view']] as $abilities) {
            $operator = User::factory()->create();
            Bouncer::allow($operator)->to('admin.access');
            foreach ($abilities as $ability) {
                Bouncer::allow($operator)->to($ability);
            }
            Bouncer::refresh();
            $response = $this->actingAs($operator)->get(route('admin.users.statistics'));
            if (count($abilities) === 2) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_customer_detail_defaults_to_the_available_historical_currency(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->create();
        $this->order($buyer, ['currency_code' => 'HRK', 'grand_total' => 752.35]);
        $this->actingAs($admin)->get(route('admin.users.statistics', ['user_id' => $buyer->id, 'dateFrom' => '', 'dateTo' => '']))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['currency'] === 'HRK')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['order_value'] === 752.35)
            ->assertSee('752,35 HRK');
    }

    public function test_search_matches_current_customer_identity_and_treats_wildcards_as_literal_text(): void
    {
        $buyer = User::factory()->create(['name' => 'Updated Customer', 'email' => 'updated@example.test']);
        $this->order($buyer, ['customer_name' => 'Old Customer', 'customer_email' => 'old@example.test', 'grand_total' => 100]);
        $this->order(null, ['customer_name' => 'Discount 10%', 'grand_total' => 20]);
        $this->order(null, ['customer_name' => 'Discount 100', 'customer_email' => 'other@example.test', 'grand_total' => 30]);
        $statistics = app(CustomerPurchaseStatistics::class);

        $this->assertSame(100.0, $statistics->summary(['currency' => 'EUR', 'search' => 'Updated Customer'])['order_value']);
        $this->assertSame(100.0, $statistics->summary(['currency' => 'EUR', 'search' => 'updated@example.test'])['order_value']);
        $this->assertSame(20.0, $statistics->summary(['currency' => 'EUR', 'search' => '10%'])['order_value']);
    }

    public function test_empty_statistics_and_invalid_filters_are_handled(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.users.statistics'))
            ->assertOk()
            ->assertSee('Nema kupaca s narudžbama')
            ->assertSee('Nema naručenih artikala')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['orders'] === 0 && $summary['average_order'] === 0.0);

        $this->actingAs($admin)->from(route('admin.users.statistics'))->get(route('admin.users.statistics', ['dateFrom' => '2026-03-01', 'dateTo' => '2026-02-01']))
            ->assertRedirect(route('admin.users.statistics'))->assertSessionHasErrors('dateFrom');
        $this->actingAs($admin)->get(route('admin.users.statistics', ['currency' => 'XXX']))->assertSessionHasErrors('currency');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);

        return $admin;
    }

    private function createStatus(string $code, array $attributes = []): OrderStatus
    {
        return OrderStatus::query()->create(array_merge([
            'code' => $code, 'name' => ucfirst($code), 'color' => 'slate', 'is_default' => false,
            'is_paid' => false, 'is_cancelled' => false, 'is_active' => true, 'sort_order' => 1,
        ], $attributes));
    }

    private function order(?User $buyer, array $attributes = []): Order
    {
        return Order::query()->forceCreate(array_merge([
            'order_number' => 'ANALYTICS-'.fake()->unique()->numerify('########'),
            'user_id' => $buyer?->id,
            'currency_code' => 'EUR', 'source' => 'web', 'customer_name' => $buyer?->name ?: 'Guest buyer',
            'customer_email' => $buyer?->email ?: 'guest@example.test',
            'grand_total' => 50, 'item_qty' => 1, 'placed_at' => '2026-02-15 12:00:00',
        ], $attributes));
    }

    private function item(Order $order, string $sku, int $quantity, float $netTotal, float $tax): void
    {
        $order->items()->create([
            'sku' => $sku, 'code' => $sku, 'name' => 'Product '.$sku, 'quantity' => $quantity,
            'unit_price' => $netTotal / $quantity, 'line_total' => $netTotal, 'tax_amount' => $tax,
        ]);
    }
}
