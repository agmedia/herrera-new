<?php

namespace Tests\Feature\Feeds;

use App\Livewire\Admin\User\CustomerCatalogFeedManager;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\CustomerCatalogFeed;
use App\Models\User\CustomerGroup;
use App\Services\Feeds\CustomerCatalogFeedAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CustomerWholesaleFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_contains_only_approved_products_with_full_wholesale_base_and_valid_escaped_xml(): void
    {
        [$admin, $buyer] = $this->actors();
        $approved = $this->product('A&B', 'Svjetlo <test> & "žarulja"');
        $this->product('NOT-APPROVED', 'Hidden product');
        $inactive = $this->product('INACTIVE', 'Inactive product');
        $inactive->update(['is_active' => false]);
        $catalog = PriceCatalog::query()->create(['name' => 'Wholesale', 'status' => 'draft', 'currency_code' => 'EUR']);
        foreach ([['base', null, null, 200.1234], ['group', null, $buyer->b2bAccount->customer_group_id, 90], ['customer', $buyer->id, null, 1]] as [$kind, $user, $group, $price]) {
            PriceCatalogEntry::query()->create(['price_catalog_id' => $catalog->id, 'source_key' => 'xml:'.$kind, 'product_id' => $approved->id, 'kind' => $kind, 'user_id' => $user, 'customer_group_id' => $group, 'price' => $price, 'minimum_quantity' => 1, 'is_active' => true]);
        }
        $catalog->update(['status' => 'active']);
        DB::table('catalog_price_catalog_state')->updateOrInsert(['id' => 1], ['price_catalog_id' => $catalog->id]);
        $access = app(CustomerCatalogFeedAccess::class);
        $feed = $access->saveAssortment($buyer, $admin, [$approved->id, $inactive->id], false);
        $token = $access->issueToken($buyer, $admin);
        $this->assertSame(hash('sha256', $token), $feed->fresh()->token_hash);
        $this->assertStringNotContainsString($token, json_encode($feed->fresh()->toArray()));
        $this->assertStringNotContainsString($token, DB::table('activity_log')->pluck('properties')->implode(' '));

        $response = $this->get(route('feed.customer-wholesale', ['token' => $token]))->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $xml = simplexml_load_string($response->streamedContent());
        $this->assertNotFalse($xml);
        $this->assertCount(1, $xml->products->product);
        $this->assertSame('A&B', (string) $xml->products->product->sku);
        $this->assertSame('Svjetlo <test> & "žarulja"', (string) $xml->products->product->name);
        $this->assertSame('200.1234', (string) $xml->products->product->price);
        $this->assertSame('full_wholesale', (string) $xml['price_basis']);
        $this->assertSame('false', (string) $xml['tax_included']);
        $this->assertSame('warehouse', (string) $xml->products->product->availability);
        $this->assertStringNotContainsString('stock_qty', $response->streamedContent());
    }

    public function test_purchased_products_are_opt_in_and_exclude_other_customers_retail_and_cancelled_orders(): void
    {
        [$admin, $buyer] = $this->actors();
        $purchased = $this->product('PURCHASED');
        $otherCustomer = $this->product('OTHER-BUYER');
        $cancelled = $this->product('CANCELLED');
        $retail = $this->product('RETAIL-HISTORY');
        $this->purchase($buyer, $purchased);
        $this->purchase(User::factory()->create(), $otherCustomer);
        $this->purchase($buyer, $retail, ['customer_type' => 'B2C']);
        $status = OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Cancelled', 'is_cancelled' => true, 'is_active' => true]);
        $this->purchase($buyer, $cancelled, ['status_id' => $status->id]);
        $access = app(CustomerCatalogFeedAccess::class);
        $access->saveAssortment($buyer, $admin, [], true);
        $token = $access->issueToken($buyer, $admin);
        $xml = simplexml_load_string($this->get(route('feed.customer-wholesale', ['token' => $token]))->assertOk()->streamedContent());
        $this->assertCount(1, $xml->products->product);
        $this->assertSame('PURCHASED', (string) $xml->products->product->sku);
        $access->saveAssortment($buyer, $admin, [], false);
        $xml = simplexml_load_string($this->get(route('feed.customer-wholesale', ['token' => $token]))->assertOk()->streamedContent());
        $this->assertCount(0, $xml->products->product);
    }

    public function test_rotation_revocation_and_account_suspension_immediately_block_old_links(): void
    {
        [$admin, $buyer] = $this->actors();
        $access = app(CustomerCatalogFeedAccess::class);
        $access->saveAssortment($buyer, $admin, [$this->product('ALLOWED')->id], false);
        $first = $access->issueToken($buyer, $admin);
        $second = $access->issueToken($buyer, $admin);
        $this->assertNotSame($first, $second);
        $this->get(route('feed.customer-wholesale', ['token' => $first]))->assertNotFound();
        $this->get(route('feed.customer-wholesale', ['token' => $second]))->assertOk();
        $buyer->b2bAccount()->update(['status' => 'suspended']);
        $this->get(route('feed.customer-wholesale', ['token' => $second]))->assertNotFound();
        $buyer->b2bAccount()->update(['status' => 'approved']);
        $access->revoke($buyer, $admin);
        $this->get(route('feed.customer-wholesale', ['token' => $second]))->assertNotFound();
        $this->assertNull(CustomerCatalogFeed::query()->sole()->token_hash);
    }

    public function test_admin_can_manage_scope_and_get_the_secret_link_once_on_customer_profile(): void
    {
        [$admin, $buyer] = $this->actors();
        $product = $this->product('UI-ALLOWED');
        $component = Livewire::actingAs($admin)->test(CustomerCatalogFeedManager::class, ['userId' => $buyer->id])
            ->call('addProduct', $product->id)->call('issueToken')->assertHasNoErrors();
        $url = $component->get('issuedUrl');
        $this->assertStringStartsWith('https://', $url);
        $this->assertSame([$product->id], CustomerCatalogFeed::query()->sole()->products()->pluck('products.id')->all());
        Livewire::actingAs($admin)->test(CustomerCatalogFeedManager::class, ['userId' => $buyer->id])->assertSet('issuedUrl', null);
        $this->actingAs($admin)->get(route('admin.users.show', $buyer))->assertOk()->assertSee('XML za webshop kupca');
        $component->call('revoke')->assertSet('issuedUrl', null);
    }

    public function test_read_only_and_unassigned_sales_staff_cannot_issue_or_expand_feeds(): void
    {
        [, $buyer] = $this->actors();
        $viewer = User::factory()->create(['account_type' => 'staff']);
        Bouncer::allow($viewer)->to('admin.access');
        Bouncer::allow($viewer)->to('catalog.b2b_prices.view');
        Bouncer::refresh();
        Livewire::actingAs($viewer)->test(CustomerCatalogFeedManager::class, ['userId' => $buyer->id])
            ->call('saveAssortment')->assertForbidden();
        $representative = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('order_manager')->to($representative);
        Bouncer::allow($representative)->to('catalog.b2b_prices.view');
        Bouncer::allow($representative)->to('catalog.b2b_prices.update');
        Bouncer::refresh();
        Livewire::actingAs($representative)->test(CustomerCatalogFeedManager::class, ['userId' => $buyer->id])->assertForbidden();
        $this->assertDatabaseCount('customer_catalog_feeds', 0);
    }

    public function test_empty_scope_pending_accounts_and_expired_contracts_cannot_gain_export_access(): void
    {
        [$admin, $buyer] = $this->actors();
        Livewire::actingAs($admin)->test(CustomerCatalogFeedManager::class, ['userId' => $buyer->id])
            ->call('issueToken')->assertHasErrors('feed')->assertSet('issuedUrl', null);
        $product = $this->product('PENDING');
        $buyer->b2bAccount()->update(['status' => 'pending']);
        Livewire::actingAs($admin)->test(CustomerCatalogFeedManager::class, ['userId' => $buyer->id])
            ->call('addProduct', $product->id)->call('issueToken')->assertHasErrors('feed')->assertSet('issuedUrl', null);
        $this->assertNull(CustomerCatalogFeed::query()->sole()->token_hash);
        $buyer->b2bAccount()->update(['status' => 'approved']);
        $access = app(CustomerCatalogFeedAccess::class);
        $token = $access->issueToken($buyer->fresh(), $admin);
        $buyer->b2bAccount()->update(['contract_ends_at' => now()->subDay()->toDateString()]);
        $this->get(route('feed.customer-wholesale', ['token' => $token]))->assertNotFound();
    }

    private function actors(): array
    {
        $admin = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('superadmin')->to($admin);
        $buyer = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'xml-group', 'name' => 'XML Group', 'is_active' => true]);
        $buyer->b2bAccount()->create(['company_name' => 'XML Company', 'oib' => '11000000001', 'status' => 'approved', 'customer_group_id' => $group->id]);

        return [$admin, $buyer->fresh()];
    }

    private function product(string $sku, string $name = 'Product'): Product
    {
        $product = Product::query()->create(['code' => $sku, 'sku' => $sku, 'base_price' => 100, 'stock_qty' => 12, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $name, 'description' => "<p>Opis &amp; detalji</p>\x01", 'slug' => 'product-'.$product->id]);

        return $product;
    }

    private function purchase(User $buyer, Product $product, array $attributes = []): void
    {
        $order = Order::query()->create($attributes + ['order_number' => uniqid('XML-'), 'user_id' => $buyer->id, 'customer_name' => $buyer->name, 'customer_email' => $buyer->email, 'item_qty' => 1, 'grand_total' => 100, 'currency_code' => 'EUR']);
        $order->items()->create(['product_id' => $product->id, 'name' => $product->sku, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
    }
}
