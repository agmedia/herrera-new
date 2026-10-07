<?php

namespace Tests\Feature\Api;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Feeds\NabavaNetFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WholesaleB2BPrivacyFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['commerce.b2b_only' => true]);
    }

    public function test_b2b_wholesale_api_does_not_expose_prices_or_stock_to_guests(): void
    {
        $this->product();

        foreach ($this->sensitiveEndpoints() as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertForbidden()->assertJsonMissingPath('data');
            $this->assertStringNotContainsString('93.37', $response->getContent());
            $this->assertStringNotContainsString('stock_qty', $response->getContent());
            $this->assertPrivateResponse($response);
        }
    }

    #[DataProvider('ineligibleAccountStates')]
    public function test_api_tokens_do_not_bypass_b2b_account_approval(string $state): void
    {
        [$user, $group, $account] = $this->approvedCustomer();
        $this->product();

        match ($state) {
            'missing' => $account->delete(),
            'pending', 'rejected', 'suspended' => $account->update(['status' => $state]),
            'expired' => $account->update(['contract_ends_at' => now()->subDay()]),
            'future' => $account->update(['contract_starts_at' => now()->addDay()]),
            'inactive_group' => $group->update(['is_active' => false]),
            'missing_group' => $account->update(['customer_group_id' => null]),
        };
        Sanctum::actingAs($user, ['*']);

        foreach ($this->sensitiveEndpoints() as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertForbidden()->assertJsonMissingPath('data');
            $this->assertStringNotContainsString('93.37', $response->getContent());
            $this->assertStringNotContainsString('stock_qty', $response->getContent());
        }
    }

    public static function ineligibleAccountStates(): array
    {
        return array_map(static fn (string $state): array => [$state], [
            'missing', 'pending', 'rejected', 'suspended', 'expired', 'future', 'inactive_group', 'missing_group',
        ]);
    }

    public function test_approved_api_customer_receives_only_the_account_audience_price(): void
    {
        [$user, $ownGroup] = $this->approvedCustomer();
        $product = $this->product();
        $otherGroup = CustomerGroup::query()->create([
            'code' => 'foreign-audience',
            'name' => 'Other customer audience',
            'is_active' => true,
        ]);
        $user->customerGroups()->sync([$ownGroup->id, $otherGroup->id]);
        foreach ([[$ownGroup, 61.11], [$otherGroup, 11.11]] as [$group, $price]) {
            ProductGroupPrice::query()->create([
                'product_id' => $product->id,
                'customer_group_id' => $group->id,
                'minimum_quantity' => 1,
                'price' => $price,
                'currency_code' => 'EUR',
                'is_active' => true,
            ]);
        }
        Sanctum::actingAs($user, ['products.read']);

        $prices = $this->getJson('/api/v1/wholesale/product_prices?customer_group_id='.$otherGroup->id);
        $prices->assertOk()
            ->assertJsonPath('data.0.price', 61.11)
            ->assertJsonMissingPath('data.0.retail_price');
        $this->assertPrivateResponse($prices);

        $listing = $this->getJson('/api/v1/wholesale/products?customer_group_id='.$otherGroup->id);
        $listing->assertOk()
            ->assertJsonPath('data.0.base_price', 61.11)
            ->assertJsonPath('data.0.payload', null);
        $this->assertPrivateResponse($listing);

        $detail = $this->getJson('/api/v1/wholesale/products/PRIVATE-SKU');
        $detail->assertOk()->assertJsonPath('data.base_price', 61.11);
        $this->assertPrivateResponse($detail);

        $quantities = $this->getJson('/api/v1/wholesale/product_quantities');
        $quantities->assertOk()->assertJsonPath('data.0.quantity', 37);
        $this->assertPrivateResponse($quantities);
    }

    public function test_approved_b2b_account_still_requires_api_access_and_token_ability(): void
    {
        [$user] = $this->approvedCustomer();
        $user->update(['api_access_enabled' => false]);
        Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/v1/wholesale/product_prices')
            ->assertForbidden()
            ->assertJsonPath('message', 'API access is disabled for this user.');

        $user->update(['api_access_enabled' => true]);
        Sanctum::actingAs($user, ['manufacturers.read']);
        $response = $this->getJson('/api/v1/wholesale/product_prices');
        $response->assertForbidden();
        $this->assertPrivateResponse($response);
    }

    public function test_public_price_feed_is_blocked_even_with_credentials_and_approved_browser_account(): void
    {
        config([
            'services.nabava_net.enabled' => true,
            'services.nabava_net.username' => 'feed-user',
            'services.nabava_net.password' => 'feed-password',
        ]);
        $this->product();
        $url = '/feeds/nabava.xml?username=feed-user&password=feed-password';

        $this->get($url)->assertForbidden();
        [$user] = $this->approvedCustomer();
        $response = $this->actingAs($user)->get($url);
        $response->assertForbidden();
        $this->assertStringNotContainsString('<artikli>', $response->getContent());
        $this->assertStringNotContainsString('93.37', $response->getContent());
    }

    public function test_public_price_feed_service_cannot_be_streamed_directly_in_b2b_mode(): void
    {
        $this->expectOutputString('');
        $this->expectException(HttpException::class);

        app(NabavaNetFeedService::class)->stream('hr');
    }

    private function sensitiveEndpoints(): array
    {
        return [
            '/api/v1/wholesale/products',
            '/api/v1/wholesale/products/PRIVATE-SKU',
            '/api/v1/wholesale/product_prices',
            '/api/v1/wholesale/product_quantities',
        ];
    }

    private function approvedCustomer(): array
    {
        $user = User::factory()->create(['api_access_enabled' => true]);
        $group = CustomerGroup::query()->create([
            'code' => 'account-'.$user->id,
            'name' => 'Approved B2B audience',
            'is_active' => true,
        ]);
        $user->customerGroups()->attach($group);
        $account = $user->b2bAccount()->create([
            'status' => B2BAccount::STATUS_APPROVED,
            'company_name' => 'Synthetic company',
            'oib' => '00000000000',
            'customer_group_id' => $group->id,
        ]);

        return [$user, $group, $account];
    }

    private function product(): Product
    {
        $product = Product::query()->create([
            'code' => 'PRIVATE-CODE',
            'sku' => 'PRIVATE-SKU',
            'base_price' => 93.37,
            'stock_qty' => 37,
            'is_active' => true,
            'payload' => ['legacy_group_prices' => ['foreign' => 11.11]],
        ]);
        $product->translations()->create([
            'locale' => 'en',
            'name' => 'Synthetic private product',
            'slug' => 'synthetic-private-product',
        ]);

        return $product;
    }

    private function assertPrivateResponse($response): void
    {
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertContains('Authorization', $response->baseResponse->getVary());
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }
}
