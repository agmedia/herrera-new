<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalog\Pricing\CatalogGroupDiscountRule;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class PriceCatalogBatchPreloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00.100000'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_one_batch_preserves_winners_regular_prices_empty_results_and_option_fallbacks(): void
    {
        [$user, $group, $catalog] = $this->fixture();
        $special = $this->product();
        $manual = $this->product();
        $empty = $this->product();
        $specialWithoutRegular = $this->product();
        $this->entry($catalog, $special, PriceCatalogEntry::BASE, '120.1234');
        $this->entry($catalog, $special, PriceCatalogEntry::GROUP, '90.1234', $group);
        $this->entry($catalog, $special, PriceCatalogEntry::CUSTOMER, '80.4321', user: $user);
        $this->entry($catalog, $special, PriceCatalogEntry::QUANTITY, '75.1234', $group);
        $winner = $this->entry($catalog, $special, PriceCatalogEntry::SPECIAL, '60.5678', $group);
        $this->entry($catalog, $special, PriceCatalogEntry::SPECIAL, '40', $group, ['priority' => 1]);
        $this->entry($catalog, $manual, PriceCatalogEntry::GROUP, '10', $group);
        $manualWinner = $this->entry($catalog, $manual, PriceCatalogEntry::GROUP, '70', $group,
            ['source_key' => 'manual-group:'.$manual->id.':'.$group->id]);
        $this->entry($catalog, $specialWithoutRegular, PriceCatalogEntry::SPECIAL, '20', $group);
        $this->publish($catalog);
        $products = collect([$special, $manual, $empty, $specialWithoutRegular]);
        $resolver = app(PriceCatalogResolver::class);
        $expected = [];
        foreach ($products as $product) {
            foreach ([150.1234, 200.5678] as $fallback) {
                $expected[$product->id][(string) $fallback] = get_object_vars($resolver->resolve($product, $user,
                    fallback: $fallback, catalog: $catalog));
            }
        }

        $queries = $this->entryQueries(function () use ($resolver, $products, $user, $expected): void {
            $resolver->preloadProducts($products->concat($products), $user);
            $resolver->preloadProducts($products, $user);
            foreach ($products as $product) {
                foreach ([150.1234, 200.5678] as $fallback) {
                    $this->assertSame($expected[$product->id][(string) $fallback],
                        get_object_vars($resolver->resolve($product, $user, fallback: $fallback)));
                }
            }
        });
        $this->assertCount(1, $queries);
        $this->assertSame($winner->id, $resolver->resolve($special, $user)?->catalog_entry_id);
        $this->assertSame(75.1234, $resolver->resolve($special, $user)?->previous_price);
        $this->assertSame($manualWinner->id, $resolver->resolve($manual, $user)?->catalog_entry_id);
        $empty->base_price = 333.1234;
        $this->assertSame(333.1234, $resolver->resolve($empty, $user)?->price);
        $this->assertSame(222.5678, $resolver->resolve($specialWithoutRegular, $user, fallback: 222.5678)?->previous_price);
    }

    public function test_batches_are_isolated_by_buyer_primary_group_quantity_and_access(): void
    {
        [$user, $group, $catalog] = $this->fixture();
        $otherGroup = $this->group();
        $sameGroupBuyer = $this->buyer($group);
        $otherBuyer = $this->buyer($otherGroup);
        $product = $this->product();
        $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '90', $group);
        $this->entry($catalog, $product, PriceCatalogEntry::CUSTOMER, '80', user: $user);
        $this->entry($catalog, $product, PriceCatalogEntry::QUANTITY, '40', $group, ['minimum_quantity' => 10]);
        $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '15', $otherGroup);
        $this->publish($catalog);
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts([$product], $user);
        $this->assertSame(80.0, $resolver->resolve($product, $user)?->price);
        $this->assertSame(90.0, $resolver->resolve($product, $sameGroupBuyer)?->price);
        $this->assertSame(15.0, $resolver->resolve($product, $otherBuyer)?->price);
        $this->assertSame(40.0, $resolver->resolve($product, $user, 10)?->price);
        $resolver->preloadProducts([$product], $user, 10);
        $this->assertCount(0, $this->entryQueries(function () use ($resolver, $product, $user): void {
            $this->assertSame(40.0, $resolver->resolve($product, $user, 10)?->price);
        }));
        $user->b2bAccount->customer_group_id = $otherGroup->id;
        $user->b2bAccount->unsetRelation('customerGroup');
        // The personal entry still wins after a primary-group change.
        $this->assertSame(80.0, $resolver->resolve($product, $user)?->price);
        $this->assertSame($otherGroup->id, $resolver->resolve($product, $user)?->customer_group_id);
        $user->b2bAccount->status = B2BAccount::STATUS_SUSPENDED;
        $this->assertNull($resolver->resolve($product, $user));
    }

    public function test_second_rollover_refreshes_the_whole_primed_set_once_and_preserves_exclusive_dates(): void
    {
        [$user, $group, $catalog] = $this->fixture();
        $products = collect([$this->product(), $this->product(), $this->product()]);
        foreach ($products as $product) {
            $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '90', $group);
            $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '50', $group,
                ['starts_at' => '2026-10-08 12:00:01', 'ends_at' => '2026-10-08 12:00:03']);
        }
        $this->publish($catalog);
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts($products, $user);
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00.900000'));
        $this->assertCount(0, $this->entryQueries(function () use ($resolver, $products, $user): void {
            foreach ($products as $product) {
                $this->assertSame(90.0, $resolver->resolve($product, $user)?->price);
            }
        }));
        foreach (['12:00:01' => 90.0, '12:00:02' => 50.0, '12:00:03' => 90.0] as $time => $price) {
            $this->travelTo(Carbon::parse('2026-10-08 '.$time));
            $queries = $this->entryQueries(function () use ($resolver, $products, $user, $price): void {
                foreach ($products as $product) {
                    $this->assertSame($price, $resolver->resolve($product, $user)?->price);
                }
            });
            $this->assertCount(1, $queries, $time);
        }
    }

    public function test_group_discount_inclusive_start_and_precedence_are_identical_to_direct_resolution(): void
    {
        [$user, $group, $catalog] = $this->fixture();
        $product = $this->product();
        $rule = CatalogGroupDiscountRule::query()->create([
            'price_catalog_id' => $catalog->id, 'name' => 'Boundary', 'percent' => 40,
            'customer_group_ids' => [$group->id], 'manufacturer_ids' => [], 'category_ids' => [], 'excluded_product_ids' => [],
        ]);
        $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '70', $group);
        $discount = $this->entry($catalog, $product, PriceCatalogEntry::GROUP_DISCOUNT, '60', $group,
            ['discount_rule_id' => $rule->id, 'starts_at' => '2026-10-08 12:00:01', 'ends_at' => '2026-10-08 12:00:03']);
        $this->publish($catalog);
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts([$product], $user);
        foreach (['12:00:00' => 70.0, '12:00:01' => 60.0, '12:00:02' => 60.0, '12:00:03' => 70.0] as $time => $price) {
            $this->travelTo(Carbon::parse('2026-10-08 '.$time));
            $batched = $resolver->resolve($product, $user);
            $direct = $resolver->resolve($product, $user, catalog: $catalog);
            $this->assertSame(get_object_vars($direct), get_object_vars($batched));
            $this->assertSame($price, $batched?->price);
            if ($price === 60.0) {
                $this->assertSame($discount->id, $batched?->catalog_entry_id);
            }
        }
    }

    public function test_explicit_catalog_and_date_simulations_bypass_batches_and_observe_draft_mutations(): void
    {
        [$user, $group, $catalog] = $this->fixture();
        $product = $this->product();
        $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '80', $group);
        $this->publish($catalog);
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts([$product], $user);
        $draft = PriceCatalog::query()->create(['name' => 'Mutable draft', 'status' => PriceCatalog::DRAFT, 'currency_code' => 'EUR']);
        $entry = $this->entry($draft, $product, PriceCatalogEntry::GROUP, '20', $group);
        $this->assertSame(20.0, $resolver->resolve($product, $user, catalog: $draft)?->price);
        $entry->update(['price' => '10']);
        $this->assertSame(10.0, $resolver->resolve($product, $user, catalog: $draft)?->price);
        $this->assertCount(1, $this->entryQueries(function () use ($resolver, $product, $user): void {
            $this->assertSame(80.0, $resolver->resolve($product, $user, at: now())?->price);
        }));
        $this->assertSame(80.0, $resolver->resolve($product, $user)?->price);
    }

    public function test_publication_and_forgetting_clear_primed_prices_and_allow_the_new_catalog(): void
    {
        [$user, $group, $catalog] = $this->fixture();
        $product = $this->product();
        $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '80', $group);
        $this->publish($catalog);
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts([$product], $user);
        $resolver->forgetActiveCatalog();
        $this->assertCount(1, $this->entryQueries(function () use ($resolver, $product, $user): void {
            $this->assertSame(80.0, $resolver->resolve($product, $user)?->price);
        }));
        $resolver->preloadProducts([$product], $user);
        $draft = PriceCatalog::query()->create(['name' => 'Replacement', 'status' => PriceCatalog::DRAFT, 'currency_code' => 'EUR']);
        $this->entry($draft, $product, PriceCatalogEntry::GROUP, '70', $group);
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        app(PriceCatalogService::class)->activate($draft, $admin);
        $this->assertSame(70.0, $resolver->resolve($product, $user)?->price);
        $this->assertSame($draft->id, $resolver->resolve($product, $user)?->catalog_id);
    }

    public function test_guests_unapproved_accounts_b2c_and_empty_sets_add_no_queries(): void
    {
        [$user] = $this->fixture();
        $product = $this->product();
        $user->load('b2bAccount.customerGroup');
        $user->b2bAccount->status = B2BAccount::STATUS_PENDING;
        $resolver = app(PriceCatalogResolver::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $resolver->preloadProducts([$product], null);
            $resolver->preloadProducts([$product], $user);
            $resolver->preloadProducts([], $user);
            config(['commerce.b2b_only' => false]);
            $resolver->preloadProducts([$product], $user);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function fixture(): array
    {
        $group = $this->group();

        return [$this->buyer($group), $group,
            PriceCatalog::query()->create(['name' => 'Batch fixture', 'status' => PriceCatalog::DRAFT, 'currency_code' => 'EUR'])];
    }

    private function group(): CustomerGroup
    {
        return CustomerGroup::query()->create(['code' => uniqid('group-'), 'name' => 'Group', 'is_active' => true]);
    }

    private function buyer(CustomerGroup $group): User
    {
        $user = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Test', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id]);

        return $user;
    }

    private function product(): Product
    {
        return Product::query()->create(['code' => uniqid('product-'), 'base_price' => '120.1234', 'is_active' => true]);
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price,
        ?CustomerGroup $group = null, array $extra = [], ?User $user = null): PriceCatalogEntry
    {
        return $catalog->entries()->create($extra + ['source_key' => uniqid('entry-'), 'product_id' => $product->id,
            'kind' => $kind, 'price' => $price, 'customer_group_id' => $group?->id, 'user_id' => $user?->id,
            'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }

    private function publish(PriceCatalog $catalog): void
    {
        $catalog->update(['status' => PriceCatalog::ACTIVE]);
        DB::table('catalog_price_catalog_state')->where('id', 1)->update(['price_catalog_id' => $catalog->id]);
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
    }

    private function entryQueries(callable $callback): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $callback();

            return array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], 'catalog_price_entries')));
        } finally {
            DB::disableQueryLog();
        }
    }
}
