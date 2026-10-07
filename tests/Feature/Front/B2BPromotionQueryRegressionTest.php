<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\CatalogGroupDiscountService;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class B2BPromotionQueryRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_promotion_bounds_and_pagination_share_one_catalogue_aggregate_without_changing_filtered_totals(): void
    {
        [$buyer, , , , , $sale, , , , $expiring] = $this->fixture();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->actingAs($buyer)->get(route('shop.index', ['promo_only' => 1]))->assertOk();
        $this->assertSame(2, $response->viewData('products')->total());
        $this->assertSame([$expiring->id, $sale->id], $response->viewData('products')->pluck('id')->all());
        $this->assertSame(['min' => 30.0, 'max' => 60.0], $response->viewData('priceBounds'));
        $aggregates = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'catalog_price_entries')
            && preg_match('/^select\s+(?:count|min|max)\s*\(/i', $query['query']) === 1);
        $this->assertCount(1, $aggregates, 'Bounds and pagination must not each scan the complete customer promotion catalogue.');

        $filtered = $this->get(route('shop.index', ['promo_only' => 1, 'price_min' => 45]))->assertOk();
        $this->assertSame(1, $filtered->viewData('products')->total());
        $this->assertSame([$sale->id], $filtered->viewData('products')->pluck('id')->all());
        $this->assertSame(['min' => 30.0, 'max' => 60.0], $filtered->viewData('priceBounds'));
        $searched = $this->get(route('shop.index', ['promo_only' => 1, 'q' => 'Expiring']))->assertOk();
        $this->assertSame(1, $searched->viewData('products')->total());
        $this->assertSame([$expiring->id], $searched->viewData('products')->pluck('id')->all());
    }

    public function test_promotion_results_follow_primary_audience_manual_prices_overrides_time_boundaries_and_catalogue_activation(): void
    {
        [$buyer, $otherBuyer, $group, , $admin, $sale, $manual, $override, $scheduled, $expiring] = $this->fixture();
        $this->actingAs($buyer)->get(route('shop.index', ['promo_only' => 1]))->assertOk()
            ->assertViewHas('products', fn ($rows): bool => $rows->total() === 2 && $rows->pluck('id')->all() === [$expiring->id, $sale->id]);
        $this->actingAs($otherBuyer)->get(route('shop.index', ['promo_only' => 1]))->assertOk()
            ->assertViewHas('products', fn ($rows): bool => $rows->total() === 1 && $rows->pluck('id')->all() === [$manual->id])
            ->assertViewHas('priceBounds', ['min' => 9.0, 'max' => 9.0]);

        $this->travel(1)->seconds();
        $this->actingAs($buyer)->get(route('shop.index', ['promo_only' => 1]))->assertOk()
            ->assertViewHas('products', fn ($rows): bool => $rows->total() === 3 && $rows->pluck('id')->all() === [$expiring->id, $scheduled->id, $sale->id]);
        $this->travel(1)->seconds();
        $this->get(route('shop.index', ['promo_only' => 1]))->assertOk()
            ->assertViewHas('products', fn ($rows): bool => $rows->total() === 2 && $rows->pluck('id')->all() === [$scheduled->id, $sale->id])
            ->assertViewHas('priceBounds', ['min' => 10.0, 'max' => 60.0]);

        $replacement = PriceCatalog::query()->create(['name' => 'Replacement', 'status' => 'draft', 'currency_code' => 'EUR']);
        $this->entry($replacement, $sale, PriceCatalogEntry::GROUP, 80, $group);
        $this->entry($replacement, $sale, PriceCatalogEntry::SPECIAL, 20, $group);
        app(PriceCatalogService::class)->activate($replacement, $admin);
        $this->get(route('shop.index', ['promo_only' => 1]))->assertOk()
            ->assertViewHas('products', fn ($rows): bool => $rows->total() === 1 && $rows->pluck('id')->all() === [$sale->id])
            ->assertViewHas('priceBounds', ['min' => 20.0, 'max' => 20.0]);
    }

    private function fixture(): array
    {
        $group = CustomerGroup::query()->create(['code' => 'promotion-primary', 'name' => 'Primary', 'is_active' => true]);
        $other = CustomerGroup::query()->create(['code' => 'promotion-other', 'name' => 'Other', 'is_active' => true]);
        $buyer = $this->buyer($group);
        $otherBuyer = $this->buyer($other);
        $buyer->customerGroups()->attach($other);
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        $catalog = PriceCatalog::query()->create(['name' => 'Promotion prices', 'status' => 'draft', 'currency_code' => 'EUR']);
        $sale = $this->product('Sale');
        $manual = $this->product('Manual');
        $override = $this->product('Override');
        $scheduled = $this->product('Scheduled');
        $expiring = $this->product('Expiring');

        foreach ([$sale, $manual, $override, $scheduled, $expiring] as $product) {
            $this->entry($catalog, $product, PriceCatalogEntry::GROUP, 80, $group);
        }
        $this->entry($catalog, $sale, PriceCatalogEntry::SPECIAL, 60, $group);
        $this->entry($catalog, $sale, PriceCatalogEntry::CUSTOMER, 1, customer: $otherBuyer);
        $this->entry($catalog, $manual, PriceCatalogEntry::GROUP, 20, $group, ['source_key' => 'manual-group:promotion', 'priority' => 100]);
        $this->entry($catalog, $manual, PriceCatalogEntry::SPECIAL, 50, $group);
        $this->entry($catalog, $manual, PriceCatalogEntry::GROUP, 70, $other);
        $this->entry($catalog, $manual, PriceCatalogEntry::SPECIAL, 9, $other);
        $this->entry($catalog, $override, PriceCatalogEntry::SPECIAL, 5, $group);
        $this->entry($catalog, $scheduled, PriceCatalogEntry::SPECIAL, 10, $group, ['starts_at' => now()]);
        $this->entry($catalog, $expiring, PriceCatalogEntry::SPECIAL, 30, $group, ['ends_at' => now()->addSeconds(2)]);
        app(CatalogGroupDiscountService::class)->saveRule($catalog, [
            'name' => 'Override promotion', 'percent' => '25', 'customer_group_ids' => [$group->id],
            'starts_at' => now(), 'excluded_product_ids' => [$sale->id, $manual->id, $scheduled->id, $expiring->id],
        ], $admin);
        app(PriceCatalogService::class)->activate($catalog, $admin);

        return [$buyer, $otherBuyer, $group, $catalog, $admin, $sale, $manual, $override, $scheduled, $expiring];
    }

    private function buyer(CustomerGroup $group): User
    {
        $user = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Test company', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);

        return $user;
    }

    private function product(string $name): Product
    {
        $product = Product::query()->create(['code' => 'promotion-'.strtolower($name), 'base_price' => 100, 'stock_qty' => 10, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => 'promotion-'.strtolower($name)]);

        return $product;
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, float $price, ?CustomerGroup $group = null, array $extra = [], ?User $customer = null): void
    {
        $catalog->entries()->create($extra + ['source_key' => uniqid(), 'product_id' => $product->id, 'kind' => $kind, 'price' => $price, 'customer_group_id' => $group?->id, 'user_id' => $customer?->id, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }
}
