<?php

namespace Tests\Feature\Admin;

use App\Jobs\Integrations\SyncSalesOpportunities;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Services\Analytics\SalesOpportunitySync;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class SalesOpportunitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_is_private_and_representatives_can_only_select_assigned_approved_partners(): void
    {
        $manager = $this->manager();
        $own = $this->customer('Own Partner', '12345678901');
        $other = $this->customer('Unassigned Private Partner', '99999999999');
        $this->assign($manager, $own);
        $this->get(route('admin.users.opportunities'))->assertRedirect(route('login'));
        $this->actingAs($own)->get(route('admin.users.opportunities'))->assertForbidden();
        $this->actingAs($manager)->get(route('admin.users.opportunities'))->assertOk()
            ->assertSee('Own Partner')->assertDontSee('Unassigned Private Partner')
            ->assertSee('Još nema potpunog ERP pregleda.')->assertDontSee('Osvježi ERP pregled');
        $this->get(route('admin.users.opportunities', ['user_id' => $other->id]))->assertForbidden();
        $own->b2bAccount->update(['status' => B2BAccount::STATUS_PENDING]);
        $this->get(route('admin.users.opportunities', ['user_id' => $own->id]))->assertUnprocessable();
    }

    public function test_company_ranking_uses_buyer_reach_then_quantity_excludes_partner_purchases_and_filters_categories(): void
    {
        $manager = $this->manager();
        $partner = $this->customer('Selected Partner', '12345678901');
        $this->assign($manager, $partner);
        $snapshotId = $this->snapshot();
        $highReach = $this->product('REACH');
        $bulk = $this->product('BULK');
        $purchased = $this->product('BOUGHT');
        $inactive = $this->product('INACTIVE', false);
        foreach (['first', 'second', 'third'] as $buyer) {
            $this->purchase($snapshotId, $highReach, $buyer, 1);
        }
        $this->purchase($snapshotId, $bulk, 'first', 1000);
        $this->purchase($snapshotId, $purchased, 'first', 5000);
        $this->purchase($snapshotId, $purchased, '12345678901', 1);
        $this->purchase($snapshotId, $inactive, 'first', 99999);
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'opportunities', 'is_active' => true]);
        $highReach->categories()->attach($category);

        $this->actingAs($manager)->get(route('admin.users.opportunities', ['user_id' => $partner->id]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$highReach->id, $bulk->id])
            ->assertSee('3')->assertDontSee('first@private.example');
        $this->get(route('admin.users.opportunities', ['user_id' => $partner->id, 'category_id' => $category->id]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$highReach->id]);
    }

    public function test_new_confirmed_purchase_removes_product_immediately_and_replaces_it_without_erp_refresh(): void
    {
        $manager = $this->manager();
        $partner = $this->customer('Selected Partner', '12345678901');
        $this->assign($manager, $partner);
        $snapshotId = $this->snapshot();
        $top = $this->product('TOP');
        $next = $this->product('NEXT');
        $this->purchase($snapshotId, $top, 'other', 100);
        $this->purchase($snapshotId, $next, 'other', 50);
        $processing = OrderStatus::query()->create(['code' => 'processing', 'name' => 'U obradi', 'is_active' => true]);
        $order = Order::query()->create(['user_id' => $partner->id, 'order_number' => 'OPP-NEW', 'source' => 'web', 'status_id' => $processing->id, 'locale' => 'hr', 'currency_code' => 'EUR', 'currency_rate' => 1, 'grand_total' => 10, 'customer_name' => $partner->name, 'customer_email' => $partner->email]);
        $order->items()->create(['product_id' => $top->id, 'name' => $top->code, 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10]);
        $this->actingAs($manager)->get(route('admin.users.opportunities', ['user_id' => $partner->id]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$next->id]);
        $cancelled = OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_active' => true, 'is_cancelled' => true]);
        $order->update(['status_id' => $cancelled->id]);
        $this->get(route('admin.users.opportunities', ['user_id' => $partner->id]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$top->id, $next->id]);
    }

    public function test_shared_cash_buyer_code_cannot_hide_another_partners_opportunities_when_tax_identity_is_known(): void
    {
        $manager = $this->manager();
        $partner = $this->customer('Selected Partner', '12345678901');
        $partner->b2bAccount->update(['payload' => ['eracuni' => ['buyerCode' => 'CASH']]]);
        $this->assign($manager, $partner);
        $snapshotId = $this->snapshot();
        $opportunity = $this->product('OTHER-BUYER-ONLY');
        $alreadyBought = $this->product('OWN-PURCHASE');
        $sync = app(SalesOpportunitySync::class);
        $otherBuyer = $sync->buyerIdentity(['buyerTaxNumber' => '99999999999', 'buyerCode' => 'CASH']);
        $ownBuyer = $sync->buyerIdentity(['buyerTaxNumber' => '12345678901', 'buyerCode' => 'OTHER-CODE']);
        DB::table('sales_opportunity_purchases')->insert([
            $otherBuyer + ['snapshot_id' => $snapshotId, 'product_id' => $opportunity->id, 'quantity_90' => 10, 'quantity_365' => 10],
            $ownBuyer + ['snapshot_id' => $snapshotId, 'product_id' => $alreadyBought->id, 'quantity_90' => 1, 'quantity_365' => 1],
        ]);

        $this->actingAs($manager)->get(route('admin.users.opportunities', ['user_id' => $partner->id]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$opportunity->id]);
    }

    public function test_only_integration_admin_can_start_refresh_and_previous_snapshot_stays_visible(): void
    {
        Bus::fake();
        $manager = $this->manager();
        $this->actingAs($manager)->post(route('admin.users.opportunities.refresh'))->assertForbidden();
        Bus::assertNotDispatched(SyncSalesOpportunities::class);
        $admin = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('admin')->to($admin);
        Bouncer::refresh();
        $this->mock(StockSyncSettingsService::class)->shouldReceive('configured')->with('eracuni')->andReturn(true);
        $this->actingAs($admin)->post(route('admin.users.opportunities.refresh'))->assertRedirect(route('admin.users.opportunities'));
        Bus::assertDispatched(SyncSalesOpportunities::class);
        $this->snapshot();
        DB::table('sales_opportunity_snapshots')->insert(['status' => 'failed', 'coverage_from' => today()->subDays(365), 'as_of' => today(), 'message' => 'Last attempt failed', 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('admin.users.opportunities'))->assertOk()->assertViewHas('snapshot', fn ($snapshot) => $snapshot->status === 'completed')
            ->assertSee('Last attempt failed');
    }

    public function test_partner_picker_includes_more_than_one_thousand_approved_partners_without_loading_private_payloads(): void
    {
        $admin = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('admin')->to($admin);
        Bouncer::refresh();
        $users = [];
        for ($index = 1; $index <= 1005; $index++) {
            $users[] = ['name' => sprintf('Partner %04d', $index), 'email' => 'picker-'.$index.'@example.test', 'password' => 'fixture-unused', 'account_type' => 'customer'];
        }
        foreach (array_chunk($users, 200) as $chunk) {
            DB::table('users')->insert($chunk);
        }
        $accounts = [];
        foreach (User::query()->where('email', 'like', 'picker-%@example.test')->orderBy('id')->get(['id', 'name']) as $user) {
            $accounts[] = ['user_id' => $user->id, 'status' => B2BAccount::STATUS_APPROVED, 'company_name' => $user->name, 'oib' => str_pad((string) $user->id, 11, '0', STR_PAD_LEFT),
                'payload' => json_encode(['private' => str_repeat('x', 4096)])];
        }
        foreach (array_chunk($accounts, 200) as $chunk) {
            DB::table('b2b_accounts')->insert($chunk);
        }
        $lastId = $accounts[array_key_last($accounts)]['user_id'];
        $this->actingAs($admin)->get(route('admin.users.opportunities', ['user_id' => $lastId]))->assertOk()
            ->assertSee('Partner 1005')->assertSee('data-tom-select', false)
            ->assertViewHas('customers', function ($customers) use ($lastId): bool {
                $this->assertCount(1005, $customers);
                $last = $customers->firstWhere('id', $lastId);
                $this->assertNotNull($last);
                $this->assertArrayNotHasKey('email', $last->getAttributes());
                $this->assertArrayNotHasKey('password', $last->getAttributes());
                $this->assertSame(['id', 'user_id', 'company_name'], array_keys($last->b2bAccount->getAttributes()));

                return true;
            });
    }

    public function test_completed_snapshot_displays_escaped_data_quality_notice_and_first_running_scan_has_correct_message(): void
    {
        $manager = $this->manager();
        DB::table('sales_opportunity_snapshots')->insert(['status' => 'running', 'coverage_from' => today()->subDays(365), 'as_of' => today(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($manager)->get(route('admin.users.opportunities'))->assertOk()
            ->assertSee('Prvi potpuni ERP pregled je u obradi.')
            ->assertDontSee('Pokrenite osvježavanje prije prikaza prodajnih prilika.')
            ->assertViewHas('products', null);
        $snapshotId = $this->snapshot();
        $notice = 'Dvosmislenih ERP oznaka kupca: 2. Količine su sačuvane; doseg koristi potvrđene identitete. <provjera>';
        DB::table('sales_opportunity_snapshots')->where('id', $snapshotId)->update(['message' => $notice]);
        $this->get(route('admin.users.opportunities'))->assertOk()->assertSee($notice)
            ->assertDontSee('<provjera>', false)->assertDontSee('Prvi potpuni ERP pregled je u obradi.');
    }

    public function test_ambiguous_shared_erp_id_cannot_hide_another_partners_products_or_match_unknown_purchases(): void
    {
        $manager = $this->manager();
        $partner = $this->customer('Selected Partner', '12345678901');
        $partner->b2bAccount->update(['erp_customer_id' => 'shared-id']);
        $erpOnly = $this->customer('ERP Only Partner', '');
        $erpOnly->b2bAccount->update(['erp_customer_id' => 'shared-id']);
        $this->assign($manager, $partner);
        $this->assign($manager, $erpOnly);
        $snapshotId = $this->snapshot();
        $opportunity = $this->product('OTHER-PARTNER');
        $ownProduct = $this->product('OWN-PURCHASE');
        $sync = app(SalesOpportunitySync::class);
        $other = $sync->buyerIdentity(['buyerTaxNumber' => '99999999999']);
        $own = $sync->buyerIdentity(['buyerTaxNumber' => '12345678901']);
        $unknown = $sync->buyerIdentity(['buyerDocumentID' => 'shared-id']);
        $unknown['known_buyer'] = false;
        $unknown['document_key'] = null;
        DB::table('sales_opportunity_purchases')->insert([
            $other + ['snapshot_id' => $snapshotId, 'product_id' => $opportunity->id, 'quantity_90' => 10, 'quantity_365' => 10],
            $own + ['snapshot_id' => $snapshotId, 'product_id' => $ownProduct->id, 'quantity_90' => 1, 'quantity_365' => 1],
            $unknown + ['snapshot_id' => $snapshotId, 'product_id' => $opportunity->id, 'quantity_90' => 5, 'quantity_365' => 5],
        ]);
        DB::table('sales_opportunity_buyer_aliases')->insert([
            ['snapshot_id' => $snapshotId, 'alias_key' => $own['tax_key'], 'buyer_key' => $own['buyer_key']],
            ['snapshot_id' => $snapshotId, 'alias_key' => $other['tax_key'], 'buyer_key' => $other['buyer_key']],
        ]);
        $this->actingAs($manager)->get(route('admin.users.opportunities', ['user_id' => $partner->id]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$opportunity->id]);
        $this->get(route('admin.users.opportunities', ['user_id' => $erpOnly->id]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$opportunity->id, $ownProduct->id]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('order_manager')->to($user);
        Bouncer::allow($user)->to('admin.access');
        Bouncer::refresh();

        return $user;
    }

    private function customer(string $name, string $tax): User
    {
        $user = User::factory()->create(['name' => $name, 'account_type' => 'customer']);
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => $name, 'status' => B2BAccount::STATUS_APPROVED, 'oib' => $tax]);

        return $user;
    }

    private function assign(User $manager, User $customer): void
    {
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $manager->id, 'customer_user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function product(string $code, bool $active = true): Product
    {
        $product = Product::query()->create(['code' => $code, 'base_price' => 10, 'stock_qty' => 10, 'is_active' => $active]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => strtolower($code)]);

        return $product;
    }

    private function snapshot(): int
    {
        return DB::table('sales_opportunity_snapshots')->insertGetId(['status' => 'completed', 'coverage_from' => today()->subDays(365), 'as_of' => today(), 'document_count' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function purchase(int $snapshotId, Product $product, string $tax, int $quantity): void
    {
        $buyer = app(SalesOpportunitySync::class)->buyerIdentity(['buyerTaxNumber' => $tax]);
        DB::table('sales_opportunity_purchases')->insert($buyer + ['snapshot_id' => $snapshotId, 'product_id' => $product->id, 'quantity_90' => $quantity, 'quantity_365' => $quantity]);
    }
}
