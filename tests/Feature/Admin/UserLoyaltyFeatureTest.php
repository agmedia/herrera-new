<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\LoyaltyManager;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\LoyaltyTransaction;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class UserLoyaltyFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_user_loyalty_page_when_enabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/users/loyalty')
            ->assertOk()
            ->assertSee('User Loyalty')
            ->assertSee(__('Order Redemption'));
    }

    public function test_loyalty_page_redirects_to_settings_when_switch_disabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', false);
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/users/loyalty')
            ->assertRedirect(route('admin.settings.user.index'))
            ->assertSessionHas('notify.type', 'warning');
    }

    public function test_editor_cannot_open_user_loyalty_page(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);
        $editor = $this->makeUserWithRole('editor');

        $this->actingAs($editor)
            ->get('/admin/users/loyalty')
            ->assertForbidden();
    }

    public function test_admin_can_filter_loyalty_transactions(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);

        $admin = $this->makeUserWithRole('admin');
        $userA = User::factory()->create(['name' => 'Alice Filter', 'email' => 'alice@example.test']);
        $userB = User::factory()->create(['name' => 'Bob Filter', 'email' => 'bob@example.test']);

        $status = OrderStatus::query()->create([
            'code' => 'paid',
            'name' => 'Paid',
            'color' => 'emerald',
            'is_default' => true,
            'is_paid' => true,
            'is_cancelled' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $order = $this->createOrder($status, $userA, 'AG-FLTR-0001');

        $aTx = LoyaltyTransaction::query()
            ->where('event_key', 'order:'.$order->id.':settlement')
            ->firstOrFail();

        $bTx = LoyaltyTransaction::query()->create([
            'user_id' => $userB->id,
            'order_id' => null,
            'event_key' => 'manual:'.$userB->id.':adj',
            'type' => 'manual_adjustment',
            'points' => -20,
            'note' => 'Manual correction',
            'payload' => null,
            'created_by' => $admin->id,
        ]);

        DB::table('loyalty_transactions')->where('id', $aTx->id)->update(['created_at' => '2026-02-10 10:00:00']);
        DB::table('loyalty_transactions')->where('id', $bTx->id)->update(['created_at' => '2026-02-12 10:00:00']);

        Livewire::actingAs($admin)
            ->test(LoyaltyManager::class)
            ->set('search', 'Alice Filter')
            ->set('type', 'order_settlement')
            ->set('dateFrom', '2026-02-09')
            ->set('dateTo', '2026-02-11')
            ->set('minPoints', '100')
            ->set('maxPoints', '200')
            ->assertSee('Alice Filter')
            ->assertSee('AG-FLTR-0001')
            ->assertSee('125')
            ->assertDontSee('Manual correction');
    }

    public function test_admin_can_create_manual_loyalty_adjustment(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);

        $admin = $this->makeUserWithRole('admin');
        $target = User::factory()->create([
            'name' => 'Manual Target',
            'email' => 'manual.target@example.test',
        ]);
        $status = OrderStatus::query()->create([
            'code' => 'new',
            'name' => 'New',
            'color' => 'slate',
            'is_default' => true,
            'is_paid' => false,
            'is_cancelled' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $order = $this->createOrder($status, $target, 'AG-MANUAL-0001');

        Livewire::actingAs($admin)
            ->test(LoyaltyManager::class)
            ->set('adjustment.user_id', $target->id)
            ->set('adjustment.order_id', $order->id)
            ->set('adjustment.points', 30)
            ->set('adjustment.reason', 'Manual correction after phone support.')
            ->call('saveManualAdjustment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $target->id,
            'order_id' => $order->id,
            'type' => 'manual_adjustment',
            'points' => 30,
            'note' => 'Manual correction after phone support.',
            'created_by' => $admin->id,
        ]);
    }

    public function test_manual_adjustment_rejects_an_order_owned_by_another_user(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);

        $admin = $this->makeUserWithRole('admin');
        $target = User::factory()->create();
        $otherUser = User::factory()->create();
        $status = OrderStatus::query()->create([
            'code' => 'new',
            'name' => 'New',
            'color' => 'slate',
            'is_default' => true,
            'is_paid' => false,
            'is_cancelled' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $otherOrder = $this->createOrder($status, $otherUser, 'AG-MISMATCH-0001');

        Livewire::actingAs($admin)
            ->test(LoyaltyManager::class)
            ->set('adjustment.user_id', $target->id)
            ->set('adjustment.order_id', $otherOrder->id)
            ->set('adjustment.points', 30)
            ->set('adjustment.reason', 'Must not link another customer order.')
            ->call('saveManualAdjustment')
            ->assertHasErrors(['adjustment.order_id']);

        $this->assertDatabaseCount('loyalty_transactions', 0);
    }

    public function test_manual_adjustment_cannot_be_saved_after_loyalty_is_disabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', false);

        $admin = $this->makeUserWithRole('admin');
        $target = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(LoyaltyManager::class)
            ->set('adjustment.user_id', $target->id)
            ->set('adjustment.points', 30)
            ->set('adjustment.reason', 'Must not write while disabled.')
            ->call('saveManualAdjustment')
            ->assertDispatched('notify');

        $this->assertDatabaseCount('loyalty_transactions', 0);
    }

    public function test_manual_adjustment_rejects_a_user_outside_eligible_customer_groups(): void
    {
        $retail = CustomerGroup::query()->create([
            'code' => 'retail-loyalty',
            'name' => 'Retail Loyalty',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 10,
        ]);
        $b2b = CustomerGroup::query()->create([
            'code' => 'b2b-no-loyalty',
            'name' => 'B2B No Loyalty',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 20,
        ]);

        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_customer_group_ids' => [$retail->id],
        ]);

        $admin = $this->makeUserWithRole('admin');
        $target = User::factory()->create();
        $target->customerGroups()->attach($b2b->id);

        Livewire::actingAs($admin)
            ->test(LoyaltyManager::class)
            ->set('adjustment.user_id', $target->id)
            ->set('adjustment.points', 30)
            ->set('adjustment.reason', 'Must reject an ineligible account.')
            ->call('saveManualAdjustment')
            ->assertHasErrors(['adjustment.user_id']);

        $this->assertDatabaseCount('loyalty_transactions', 0);
    }

    public function test_loyalty_manager_can_boot_with_user_scope_query(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);

        $admin = $this->makeUserWithRole('admin');
        $target = User::factory()->create();

        Livewire::withQueryParams(['user_id' => $target->id])
            ->actingAs($admin)
            ->test(LoyaltyManager::class)
            ->assertSet('userId', (string) $target->id)
            ->assertSet('adjustment.user_id', $target->id)
            ->assertSee(__('Scoped User:'))
            ->assertSee($target->name);
    }

    private function makeUserWithRole(string $role): User
    {
        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::role()->firstOrCreate(['name' => 'editor']);
        Bouncer::role()->firstOrCreate(['name' => 'customer']);

        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }

    private function createOrder(OrderStatus $status, User $user, string $number): Order
    {
        return Order::query()->create([
            'order_number' => $number,
            'status_id' => $status->id,
            'user_id' => $user->id,
            'source' => 'web',
            'locale' => 'en',
            'currency_code' => 'EUR',
            'currency_rate' => 1,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'customer_phone' => '+38591000111',
            'billing_first_name' => 'Test',
            'billing_last_name' => 'User',
            'billing_address_line_1' => 'Street 1',
            'billing_postal_code' => '10000',
            'billing_city' => 'Zagreb',
            'billing_country_code' => 'HR',
            'shipping_first_name' => 'Test',
            'shipping_last_name' => 'User',
            'shipping_address_line_1' => 'Street 1',
            'shipping_postal_code' => '10000',
            'shipping_city' => 'Zagreb',
            'shipping_country_code' => 'HR',
            'payment_method_code' => 'bank',
            'payment_method_name' => 'Bank Transfer',
            'shipping_method_code' => 'standard',
            'shipping_method_name' => 'Standard Shipping',
            'item_qty' => 1,
            'subtotal' => 99.90,
            'shipping_total' => 4.99,
            'payment_fee_total' => 0,
            'discount_total' => 0,
            'tax_total' => 20,
            'grand_total' => 124.89,
            'customer_note' => null,
            'admin_note' => null,
            'payload' => null,
            'placed_at' => now(),
            'paid_at' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
