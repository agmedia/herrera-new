<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Sales\Order\Manager as OrderManager;
use App\Livewire\Admin\Sales\Order\Show as OrderShow;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\LoyaltyTransaction;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class OrdersFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_orders_index_and_show_page(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $status = $this->createStatus(code: 'new', name: 'New', isDefault: true);

        $order = $this->createOrder($status, $admin, 'AG-TEST-0001');

        $this->actingAs($admin)->get('/admin/orders')
            ->assertOk()
            ->assertSee('Orders');

        $this->actingAs($admin)->get('/admin/orders/'.$order->id.'/show')
            ->assertOk()
            ->assertSee('AG-TEST-0001');

        $this->actingAs($admin)->get('/admin/orders/'.$order->id.'/invoice')
            ->assertOk()
            ->assertSee(__('Invoice'));
    }

    public function test_admin_can_delete_order_from_index_manager(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $status = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($status, $admin, 'AG-TEST-DELETE-1');

        $order->items()->create([
            'product_id' => null,
            'product_option_value_id' => null,
            'sku' => 'DELETE-SKU',
            'code' => 'DELETE-CODE',
            'name' => 'Delete Item',
            'unit_price' => 99.90,
            'discount_amount' => 0,
            'tax_rate' => 25,
            'tax_amount' => 24.99,
            'quantity' => 1,
            'line_total' => 124.89,
            'sort_order' => 0,
            'payload' => null,
        ]);

        LoyaltyTransaction::query()->create([
            'user_id' => $admin->id,
            'order_id' => $order->id,
            'event_key' => 'order:'.$order->id.':manual-delete-test',
            'type' => 'manual_adjustment',
            'points' => 10,
            'note' => 'Delete test',
            'payload' => null,
            'created_by' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->test(OrderManager::class)
            ->call('delete', $order->id)
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('orders', [
            'id' => $order->id,
        ]);
        $this->assertDatabaseMissing('order_items', [
            'order_id' => $order->id,
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':manual-delete-test',
            'order_id' => null,
        ]);
    }

    public function test_customer_cannot_open_orders_pages(): void
    {
        $customer = $this->makeUserWithRole('customer');
        $status = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($status, $customer, 'AG-TEST-0002');

        $this->actingAs($customer)->get('/admin/orders')->assertForbidden();
        $this->actingAs($customer)->get('/admin/orders/'.$order->id.'/show')->assertForbidden();
        $this->actingAs($customer)->get('/admin/orders/'.$order->id.'/invoice')->assertForbidden();
    }

    public function test_admin_can_update_order_status_and_write_history_entry(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);

        $order = $this->createOrder($new, $admin, 'AG-TEST-0003');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)
            ->set('form.comment', 'Payment confirmed by bank transfer.')
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status_id' => $paid->id,
        ]);

        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'from_status_id' => $new->id,
            'to_status_id' => $paid->id,
            'changed_by' => $admin->id,
            'comment' => 'Payment confirmed by bank transfer.',
        ]);

        $this->assertNotNull($order->fresh()?->paid_at);
    }

    public function test_admin_quick_status_action_updates_order(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);

        $order = $this->createOrder($new, $admin, 'AG-TEST-0004');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->call('quickStatusByCode', 'paid')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status_id' => $paid->id,
        ]);

        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'from_status_id' => $new->id,
            'to_status_id' => $paid->id,
        ]);
    }

    public function test_admin_can_add_and_remove_internal_tags(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $status = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($status, $admin, 'AG-TEST-0005');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('tagInput', 'priority')
            ->call('addInternalTag')
            ->set('tagInput', 'call-customer')
            ->call('addInternalTag')
            ->call('removeInternalTag', 'priority')
            ->assertHasNoErrors();

        $fresh = $order->fresh();
        $payload = (array) ($fresh?->payload ?? []);
        $tags = (array) ($payload['internal_tags'] ?? []);

        $this->assertContains('call-customer', $tags);
        $this->assertNotContains('priority', $tags);
    }

    public function test_loyalty_settlement_is_created_on_paid_status_when_enabled(): void
    {
        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 1.0,
            'loyalty_min_order_total' => 100.0,
            'loyalty_customer_group_ids' => [],
        ]);

        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);

        $order = $this->createOrder($new, $admin, 'AG-TEST-0006');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $admin->id,
            'order_id' => $order->id,
            'event_key' => 'order:'.$order->id.':settlement',
            'type' => 'order_settlement',
            'points' => 125,
        ]);

        $this->actingAs($admin)
            ->get('/admin/orders/'.$order->id.'/show')
            ->assertOk()
            ->assertSee(__('Loyalty Settlement:'))
            ->assertSee(__('Loyalty Redemption'));
    }

    public function test_direct_eloquent_status_changes_award_and_reverse_loyalty_points(): void
    {
        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 1.0,
            'loyalty_min_order_total' => 0.0,
            'loyalty_reversal_mode' => 'zero_out',
            'loyalty_customer_group_ids' => [],
        ]);

        $customer = $this->makeUserWithRole('customer');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);
        $cancelled = $this->createStatus(code: 'cancelled', name: 'Cancelled', isCancelled: true, sortOrder: 3);
        $order = $this->createOrder($new, $customer, 'AG-DIRECT-STATUS');

        $order->status_id = $paid->id;
        $order->save();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':settlement',
            'points' => 125,
        ]);

        $order->status_id = $cancelled->id;
        $order->save();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':settlement',
            'points' => 0,
        ]);
    }

    public function test_initial_order_status_only_awards_loyalty_when_created_as_paid(): void
    {
        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 1.0,
            'loyalty_min_order_total' => 0.0,
            'loyalty_customer_group_ids' => [],
        ]);

        $customer = $this->makeUserWithRole('customer');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);

        $unpaidOrder = $this->createOrder($new, $customer, 'AG-INITIAL-UNPAID');
        $paidOrder = $this->createOrder($paid, $customer, 'AG-INITIAL-PAID');

        $this->assertDatabaseMissing('loyalty_transactions', [
            'event_key' => 'order:'.$unpaidOrder->id.':settlement',
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$paidOrder->id.':settlement',
            'points' => 125,
        ]);
    }

    public function test_loyalty_settlement_is_not_created_when_disabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', false);

        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);

        $order = $this->createOrder($new, $admin, 'AG-TEST-0007');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':settlement',
        ]);
    }

    public function test_order_detail_hides_loyalty_controls_when_feature_is_disabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', false);

        $admin = $this->makeUserWithRole('admin');
        $status = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($status, $admin, 'AG-TEST-0007B');
        $order->totals()->create([
            'code' => 'loyalty_redemption',
            'title' => 'Archived reward discount',
            'value' => -10,
            'sort_order' => 650,
            'payload' => null,
        ]);

        $this->actingAs($admin)
            ->get('/admin/orders/'.$order->id.'/show')
            ->assertOk()
            ->assertDontSee('Loyalty Settlement')
            ->assertDontSee('Loyalty Redemption')
            ->assertDontSee('Archived reward discount');

        $this->actingAs($admin)
            ->get('/admin/orders/'.$order->id.'/invoice')
            ->assertOk()
            ->assertDontSee('Archived reward discount');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('redeemPoints', 50)
            ->call('applyLoyaltyRedemption')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':redemption',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'discount_total' => 0.00,
            'grand_total' => 124.89,
        ]);
    }

    public function test_selected_customer_groups_control_loyalty_earning_and_redemption(): void
    {
        $retail = CustomerGroup::query()->create([
            'code' => 'retail',
            'name' => 'Retail',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 10,
        ]);
        $b2b = CustomerGroup::query()->create([
            'code' => 'b2b',
            'name' => 'B2B',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 20,
        ]);

        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 1.0,
            'loyalty_currency_value_per_point' => 0.01,
            'loyalty_min_order_total' => 0.0,
            'loyalty_customer_group_ids' => [$retail->id],
        ]);

        $admin = $this->makeUserWithRole('admin');
        $eligible = $this->makeUserWithRole('customer');
        $ineligible = $this->makeUserWithRole('customer');
        $eligible->customerGroups()->attach($retail->id);
        $ineligible->customerGroups()->attach($b2b->id);

        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);
        $eligibleEarningOrder = $this->createOrder($new, $eligible, 'AG-GROUP-EARN-YES');
        $ineligibleEarningOrder = $this->createOrder($new, $ineligible, 'AG-GROUP-EARN-NO');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $eligibleEarningOrder->id])
            ->set('form.status_id', $paid->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $ineligibleEarningOrder->id])
            ->set('form.status_id', $paid->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$eligibleEarningOrder->id.':settlement',
            'points' => 125,
        ]);
        $this->assertDatabaseMissing('loyalty_transactions', [
            'event_key' => 'order:'.$ineligibleEarningOrder->id.':settlement',
        ]);

        LoyaltyTransaction::query()->create([
            'user_id' => $ineligible->id,
            'order_id' => null,
            'event_key' => 'legacy-b2b-points:'.$ineligible->id,
            'type' => 'manual_adjustment',
            'points' => 200,
            'note' => 'Legacy balance before eligibility restriction.',
            'payload' => null,
            'created_by' => $admin->id,
        ]);

        $eligibleRedemptionOrder = $this->createOrder($new, $eligible, 'AG-GROUP-REDEEM-YES');
        $ineligibleRedemptionOrder = $this->createOrder($new, $ineligible, 'AG-GROUP-REDEEM-NO');

        $this->actingAs($admin)
            ->get('/admin/orders/'.$eligibleRedemptionOrder->id.'/show')
            ->assertOk()
            ->assertSee('wire:click="applyLoyaltyRedemption"', false);

        $this->actingAs($admin)
            ->get('/admin/orders/'.$ineligibleRedemptionOrder->id.'/show')
            ->assertOk()
            ->assertDontSee('wire:click="applyLoyaltyRedemption"', false);

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $eligibleRedemptionOrder->id])
            ->set('redeemPoints', 50)
            ->call('applyLoyaltyRedemption')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $ineligibleRedemptionOrder->id])
            ->set('redeemPoints', 50)
            ->call('applyLoyaltyRedemption')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$eligibleRedemptionOrder->id.':redemption',
            'points' => -50,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $eligibleRedemptionOrder->id,
            'discount_total' => 0.50,
            'grand_total' => 124.39,
        ]);
        $this->assertDatabaseMissing('loyalty_transactions', [
            'event_key' => 'order:'.$ineligibleRedemptionOrder->id.':redemption',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $ineligibleRedemptionOrder->id,
            'discount_total' => 0.00,
            'grand_total' => 124.89,
        ]);
    }

    public function test_cancellation_reconciles_existing_points_after_customer_becomes_ineligible(): void
    {
        $retail = CustomerGroup::query()->create([
            'code' => 'retail-reconciliation',
            'name' => 'Retail Reconciliation',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 10,
        ]);
        $b2b = CustomerGroup::query()->create([
            'code' => 'b2b-reconciliation',
            'name' => 'B2B Reconciliation',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 20,
        ]);

        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 1.0,
            'loyalty_min_order_total' => 0.0,
            'loyalty_reversal_mode' => 'zero_out',
            'loyalty_customer_group_ids' => [$retail->id],
        ]);

        $admin = $this->makeUserWithRole('admin');
        $customer = $this->makeUserWithRole('customer');
        $customer->customerGroups()->attach($retail->id);
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);
        $cancelled = $this->createStatus(code: 'cancelled', name: 'Cancelled', isCancelled: true, sortOrder: 3);
        $order = $this->createOrder($new, $customer, 'AG-GROUP-RECONCILE');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':settlement',
            'points' => 125,
        ]);

        app(SystemSettingsService::class)->put('loyalty_customer_group_ids', [$b2b->id]);

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $cancelled->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':settlement',
            'points' => 0,
        ]);
        $this->assertSame(0, (int) LoyaltyTransaction::query()->where('user_id', $customer->id)->sum('points'));

        app(SystemSettingsService::class)->put('loyalty_customer_group_ids', [$retail->id]);
        app(LoyaltyService::class)->syncOrderSettlement($order->fresh());

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':settlement',
            'points' => 0,
        ]);
        $this->assertSame(0, (int) LoyaltyTransaction::query()->where('user_id', $customer->id)->sum('points'));
    }

    public function test_loyalty_reversal_creates_separate_negative_entry_when_mode_is_separate_entry(): void
    {
        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 1.0,
            'loyalty_min_order_total' => 0.0,
            'loyalty_reversal_mode' => 'separate_entry',
        ]);

        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);
        $cancelled = $this->createStatus(code: 'cancelled', name: 'Cancelled', isCancelled: true, sortOrder: 3);

        $order = $this->createOrder($new, $admin, 'AG-TEST-0008');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)
            ->call('updateStatus')
            ->set('form.status_id', $cancelled->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':settlement',
            'type' => 'order_settlement',
            'points' => 125,
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':reversal',
            'type' => 'order_reversal',
            'points' => -125,
        ]);

        $this->assertTrue(
            Activity::query()
                ->where('log_name', 'loyalty')
                ->where('event', 'order_settlement_synced')
                ->where('subject_type', Order::class)
                ->where('subject_id', $order->id)
                ->exists()
        );
        $this->assertTrue(
            Activity::query()
                ->where('log_name', 'loyalty')
                ->where('event', 'order_reversal_synced')
                ->where('subject_type', Order::class)
                ->where('subject_id', $order->id)
                ->exists()
        );
    }

    public function test_loyalty_reversal_zeroes_settlement_without_extra_row_in_zero_out_mode(): void
    {
        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 1.0,
            'loyalty_min_order_total' => 0.0,
            'loyalty_reversal_mode' => 'zero_out',
        ]);

        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);
        $cancelled = $this->createStatus(code: 'cancelled', name: 'Cancelled', isCancelled: true, sortOrder: 3);

        $order = $this->createOrder($new, $admin, 'AG-TEST-0009');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)
            ->call('updateStatus')
            ->set('form.status_id', $cancelled->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':settlement',
            'type' => 'order_settlement',
            'points' => 0,
        ]);
        $this->assertDatabaseMissing('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':reversal',
        ]);

        $settlementLogs = Activity::query()
            ->where('log_name', 'loyalty')
            ->where('event', 'order_settlement_synced')
            ->where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->get();

        $this->assertTrue(
            $settlementLogs->contains(fn (Activity $log): bool => (int) $log->getExtraProperty('to_points') === 0)
        );
    }

    public function test_admin_can_apply_and_clear_loyalty_redemption_on_order(): void
    {
        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 1.0,
            'loyalty_currency_value_per_point' => 1.0,
            'loyalty_min_order_total' => 0.0,
            'loyalty_customer_group_ids' => [],
        ]);

        $admin = $this->makeUserWithRole('admin');
        $status = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($status, $admin, 'AG-TEST-0010');
        $order->totals()->create([
            'code' => 'grand_total',
            'title' => 'Grand Total',
            'value' => 124.89,
            'sort_order' => 900,
        ]);

        LoyaltyTransaction::query()->create([
            'user_id' => $admin->id,
            'order_id' => null,
            'event_key' => 'seed:loyalty:'.$admin->id,
            'type' => 'manual_adjustment',
            'points' => 200,
            'note' => 'Seed points for redemption test.',
            'payload' => null,
            'created_by' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('redeemPoints', 50)
            ->call('applyLoyaltyRedemption')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':redemption',
            'type' => 'order_redemption',
            'points' => -50,
        ]);
        $this->assertDatabaseHas('order_totals', [
            'order_id' => $order->id,
            'code' => 'loyalty_redemption',
            'value' => -50.00,
        ]);
        $this->assertDatabaseHas('order_totals', [
            'order_id' => $order->id,
            'code' => 'grand_total',
            'value' => 74.89,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'discount_total' => 50.00,
            'grand_total' => 74.89,
        ]);

        $this->actingAs($admin)
            ->get('/admin/orders/'.$order->id.'/invoice')
            ->assertOk()
            ->assertSee(__('Loyalty Redemption'));

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('redeemPoints', 0)
            ->call('applyLoyaltyRedemption')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':redemption',
        ]);
        $this->assertDatabaseMissing('order_totals', [
            'order_id' => $order->id,
            'code' => 'loyalty_redemption',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'discount_total' => 0.00,
            'grand_total' => 124.89,
        ]);
        $this->assertDatabaseHas('order_totals', [
            'order_id' => $order->id,
            'code' => 'grand_total',
            'value' => 124.89,
        ]);

        $this->assertTrue(
            Activity::query()
                ->where('log_name', 'loyalty')
                ->where('event', 'order_redemption_synced')
                ->where('subject_type', Order::class)
                ->where('subject_id', $order->id)
                ->exists()
        );
    }

    public function test_loyalty_redemption_caps_to_available_balance_and_order_max(): void
    {
        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 2.0,
            'loyalty_currency_value_per_point' => 0.5,
            'loyalty_min_order_total' => 0.0,
        ]);

        $admin = $this->makeUserWithRole('admin');
        $status = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($status, $admin, 'AG-TEST-0011');

        LoyaltyTransaction::query()->create([
            'user_id' => $admin->id,
            'order_id' => null,
            'event_key' => 'seed:loyalty:cap:'.$admin->id,
            'type' => 'manual_adjustment',
            'points' => 30,
            'note' => 'Seed points for cap test.',
            'payload' => null,
            'created_by' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('redeemPoints', 1000)
            ->call('applyLoyaltyRedemption')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':redemption',
            'points' => -30,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'discount_total' => 15.00,
            'grand_total' => 109.89,
        ]);
    }

    public function test_loyalty_earning_and_redemption_rates_are_independent(): void
    {
        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 2.0,
            'loyalty_currency_value_per_point' => 0.01,
            'loyalty_min_order_total' => 0.0,
        ]);

        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);
        $earningOrder = $this->createOrder($new, $admin, 'AG-TEST-RATES-EARN');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $earningOrder->id])
            ->set('form.status_id', $paid->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        $redemptionOrder = $this->createOrder($new, $admin, 'AG-TEST-RATES-REDEEM');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $redemptionOrder->id])
            ->set('redeemPoints', 100)
            ->call('applyLoyaltyRedemption')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$earningOrder->id.':settlement',
            'points' => 250,
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$redemptionOrder->id.':redemption',
            'points' => -100,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $redemptionOrder->id,
            'discount_total' => 1.00,
            'grand_total' => 123.89,
        ]);
    }

    public function test_adding_a_note_does_not_recalculate_existing_loyalty_settlement(): void
    {
        app(SystemSettingsService::class)->putMany([
            'user_loyalty_enabled' => true,
            'loyalty_points_per_currency' => 1.0,
            'loyalty_min_order_total' => 0.0,
        ]);

        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true, sortOrder: 2);
        $order = $this->createOrder($new, $admin, 'AG-TEST-NOTE-1');

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)
            ->call('updateStatus')
            ->assertHasNoErrors();

        app(SystemSettingsService::class)->put('loyalty_points_per_currency', 2.0);

        Livewire::actingAs($admin)
            ->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)
            ->set('form.comment', 'Administrative note only.')
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loyalty_transactions', [
            'event_key' => 'order:'.$order->id.':settlement',
            'points' => 125,
        ]);
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

    private function createStatus(
        string $code,
        string $name,
        bool $isDefault = false,
        bool $isPaid = false,
        bool $isCancelled = false,
        int $sortOrder = 1
    ): OrderStatus {
        return OrderStatus::query()->create([
            'code' => $code,
            'name' => $name,
            'description' => null,
            'color' => 'slate',
            'is_default' => $isDefault,
            'is_paid' => $isPaid,
            'is_cancelled' => $isCancelled,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'settings' => null,
        ]);
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
