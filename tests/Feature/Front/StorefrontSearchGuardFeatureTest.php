<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Front\StorefrontSearchPolicy;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontSearchGuardFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        app(SystemSettingsService::class)->putMany([
            'store_search_autocomplete_enabled' => true,
            'store_search_autocomplete_products_enabled' => true,
            'store_search_autocomplete_show_product_price' => true,
        ]);
    }

    #[DataProvider('invalidSearches')]
    public function test_invalid_queries_are_rejected_before_product_sql(mixed $query): void
    {
        $productQueries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$productQueries): void {
            if (preg_match('/(?:from|join) ["`]?products["`]?\b/i', $query->sql)) {
                $productQueries++;
            }
        });

        $response = $this->getJson(route('shop.index', ['q' => $query]))->assertUnprocessable()
            ->assertJsonValidationErrors('q');
        $this->assertSame(0, $productQueries);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public static function invalidSearches(): array
    {
        return [
            'array shape' => [['lamp']],
            'too many characters' => [str_repeat('a', 101)],
            'too many bytes' => [str_repeat('𐐀', 101)],
            'too many terms' => [implode(' ', array_fill(0, 13, 'lamp'))],
            'wildcard only' => ['%__%'],
            'punctuation only' => ['*** !?'],
            'wildcard repetition' => ['LED%%%%%'],
            'control character' => ["LED\0lamp"],
            'invalid utf8' => ["LED\xFF"],
        ];
    }

    public function test_query_normalization_preserves_croatian_names_and_literal_identifier_characters(): void
    {
        $this->getJson(route('search.autocomplete', ['q' => "  LED\t žarulja\n "]))->assertOk()
            ->assertJsonPath('query', 'LED žarulja');
        $policy = app(StorefrontSearchPolicy::class);
        $this->assertSame('BT_20%!', $policy->normalize('BT_20%!'));
        $pattern = $policy->literalLike('BT_20%!');
        $this->assertSame('BT!_20!%!!', $pattern);
        $this->assertSame(1, (int) DB::selectOne("SELECT ? LIKE ? ESCAPE '!' AS matches", ['BT_20%!', $pattern])->matches);
        $this->assertSame(0, (int) DB::selectOne("SELECT ? LIKE ? ESCAPE '!' AS matches", ['BTX20000!', $pattern])->matches);
    }

    public function test_excessive_or_malformed_search_pages_are_rejected_before_sql_on_every_search_surface(): void
    {
        foreach (['shop.index' => [], 'search.autocomplete' => [], 'categories.show' => ['slug' => 'missing'], 'manufacturers.show' => ['slug' => 'missing']] as $route => $parameters) {
            foreach ([1001, '1e9', ['1']] as $page) {
                $this->getJson(route($route, array_merge($parameters, ['q' => 'lamp', 'page' => $page])))
                    ->assertUnprocessable()->assertJsonValidationErrors('page');
            }
        }
    }

    public function test_search_surfaces_share_burst_and_sustained_limits_with_retry_after_and_private_responses(): void
    {
        config(['storefront-search.limits.guest.burst' => 2, 'storefront-search.limits.guest.per_minute' => 3]);
        $this->getJson(route('search.autocomplete', ['q' => 'lamp']))->assertOk();
        $this->getJson(route('search.autocomplete', ['q' => 'cable']))->assertOk();
        $burst = $this->getJson(route('categories.show', ['slug' => 'missing', 'q' => 'lamp']))->assertStatus(429);
        $this->assertGreaterThan(0, (int) $burst->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(10, (int) $burst->headers->get('Retry-After'));
        $this->assertStringContainsString('private', (string) $burst->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $burst->headers->get('Cache-Control'));

        $this->travel(11)->seconds();
        $this->getJson(route('search.autocomplete', ['q' => 'lamp']))->assertOk();
        $sustained = $this->getJson(route('manufacturers.show', ['slug' => 'missing', 'q' => 'lamp']))->assertStatus(429);
        $this->assertGreaterThan(10, (int) $sustained->headers->get('Retry-After'));
    }

    public function test_one_authenticated_user_cannot_reset_their_search_budget_by_changing_ips(): void
    {
        config(['storefront-search.limits.user.burst' => 2, 'storefront-search.limits.user.per_minute' => 100]);
        $this->actingAs(User::factory()->create());
        foreach (['192.0.2.1', '192.0.2.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson(route('search.autocomplete', ['q' => 'lamp']))->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])->getJson(route('search.autocomplete', ['q' => 'lamp']))->assertStatus(429);
    }

    public function test_one_ip_cannot_reset_the_shared_budget_by_rotating_authenticated_users(): void
    {
        config(['storefront-search.limits.user.burst' => 100, 'storefront-search.limits.authenticated_ip.burst' => 2]);
        foreach (range(1, 3) as $attempt) {
            $response = $this->actingAs(User::factory()->create())->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
                ->getJson(route('search.autocomplete', ['q' => 'lamp']));
            $response->assertStatus($attempt < 3 ? 200 : 429);
        }
    }

    public function test_exhausted_search_budget_does_not_block_browsing_or_checkout_and_b2b_data_stays_private(): void
    {
        config(['commerce.b2b_only' => true, 'storefront-search.limits.guest.burst' => 1]);
        $product = Product::query()->create(['code' => 'GUARD-LAMP', 'is_active' => true, 'base_price' => 9876.54, 'stock_qty' => 93847]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Guard lamp', 'slug' => 'guard-lamp']);
        $this->getJson(route('search.autocomplete', ['q' => 'Guard']))->assertOk()
            ->assertDontSee('9876.54', false)->assertDontSee('93847', false);
        $this->getJson(route('shop.index', ['q' => 'Guard']))->assertStatus(429);
        $this->get(route('shop.index'))->assertOk()->assertDontSee('9876.54', false)->assertDontSee('93847', false);
        $this->get(route('shop.index', ['q' => '   ']))->assertOk();
        $this->get(route('checkout.create'))->assertRedirect(route('front.auth.login'));
    }
}
