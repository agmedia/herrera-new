<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Sales\Order\Manager as OrderManager;
use App\Livewire\Admin\Sales\Order\Show as OrderShow;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\LoyaltyTransaction;
use App\Services\Integrations\Gls\GlsShipmentService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

    public function test_historical_billing_identity_is_visible_only_to_owner_and_authorized_admin(): void
    {
        $buyer = $this->makeUserWithRole('customer');
        $status = $this->createStatus(code: 'historical', name: 'Historical');
        $order = $this->createOrder($status, $buyer, 'OC-HERRERA-IDENTITY');
        $order->update(['billing_company' => 'Historical Herrera Buyer', 'billing_oib' => 'DE123456789', 'billing_vat_id' => 'HR12345678901']);
        $this->actingAs($buyer)->get('/account/orders/'.$order->order_number)->assertOk()
            ->assertSee('Historical Herrera Buyer')->assertSee('DE123456789')->assertSee('HR12345678901');
        $other = $this->makeUserWithRole('customer');
        $this->actingAs($other)->get('/account/orders/'.$order->order_number)->assertNotFound()
            ->assertDontSee('Historical Herrera Buyer')->assertDontSee('DE123456789');
        $this->actingAs($other)->get('/admin/orders/'.$order->id.'/invoice')->assertForbidden();
        $admin = $this->makeUserWithRole('admin');
        foreach (['show', 'invoice'] as $view) {
            $this->actingAs($admin)->get('/admin/orders/'.$order->id.'/'.$view)->assertOk()
                ->assertSee('Historical Herrera Buyer')->assertSee('DE123456789')->assertSee('HR12345678901');
        }
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

    public function test_guest_is_redirected_to_login_for_order_pages(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $status = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($status, $admin, 'AG-GUEST');

        foreach (['/admin/orders', '/admin/orders/'.$order->id.'/show', '/admin/orders/'.$order->id.'/invoice'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_customer_cannot_mount_order_components_directly(): void
    {
        $customer = $this->makeUserWithRole('customer');
        $status = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($status, $customer, 'AG-FORBIDDEN-COMPONENT');

        Livewire::actingAs($customer)->test(OrderManager::class)->assertForbidden();
        Livewire::actingAs($customer)->test(OrderShow::class, ['orderId' => $order->id])->assertForbidden();
    }

    public function test_editor_can_read_orders_but_cannot_change_or_delete_them(): void
    {
        $editor = $this->makeUserWithRole('editor');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true);
        $order = $this->createOrder($new, $editor, 'AG-READ-ONLY');
        $order->update(['payload' => ['internal_tags' => ['keep-tag']]]);

        $this->actingAs($editor)->get('/admin/orders')->assertOk();
        $this->get('/admin/orders/'.$order->id.'/show')->assertOk();
        $this->get('/admin/orders/'.$order->id.'/invoice')->assertOk();
        $this->post('/admin/orders/'.$order->id.'/gls/send')->assertForbidden();

        Livewire::actingAs($editor)->test(OrderManager::class)
            ->call('delete', $order->id)->assertForbidden();
        Livewire::actingAs($editor)->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)->call('updateStatus')->assertForbidden();
        Livewire::actingAs($editor)->test(OrderShow::class, ['orderId' => $order->id])
            ->call('quickStatusByCode', 'paid')->assertForbidden();
        Livewire::actingAs($editor)->test(OrderShow::class, ['orderId' => $order->id])
            ->set('tagInput', 'unauthorized-tag')->call('addInternalTag')->assertForbidden();
        Livewire::actingAs($editor)->test(OrderShow::class, ['orderId' => $order->id])
            ->call('removeInternalTag', 'keep-tag')->assertForbidden();
        Livewire::actingAs($editor)->test(OrderShow::class, ['orderId' => $order->id])
            ->call('applyLoyaltyRedemption')->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status_id' => $new->id]);
        $this->assertSame(['keep-tag'], $order->fresh()->payload['internal_tags']);
        $this->assertSame(0, $order->history()->count());
    }

    public function test_status_update_rejects_missing_unknown_and_inactive_statuses(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $inactive = $this->createStatus(code: 'archived', name: 'Archived');
        $inactive->update(['is_active' => false]);
        $order = $this->createOrder($new, $admin, 'AG-INVALID-STATUS');

        foreach ([null, 999999, $inactive->id] as $statusId) {
            Livewire::actingAs($admin)->test(OrderShow::class, ['orderId' => $order->id])
                ->set('form.status_id', $statusId)
                ->call('updateStatus')
                ->assertHasErrors(['form.status_id']);
        }

        $this->assertSame($new->id, $order->fresh()->status_id);
        $this->assertSame(0, $order->history()->count());
    }

    public function test_status_update_rejects_an_oversized_comment_without_partial_save(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true);
        $order = $this->createOrder($new, $admin, 'AG-INVALID-COMMENT');

        Livewire::actingAs($admin)->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.status_id', $paid->id)
            ->set('form.comment', str_repeat('x', 2001))
            ->call('updateStatus')
            ->assertHasErrors(['form.comment' => 'max']);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status_id' => $new->id,
            'admin_note' => null,
            'paid_at' => null,
        ]);
        $this->assertSame(0, $order->history()->count());
    }

    public function test_saving_unchanged_status_without_a_note_does_not_create_history(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($new, $admin, 'AG-NO-CHANGE');

        Livewire::actingAs($admin)->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.comment', '   ')
            ->call('updateStatus')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'info');

        $this->assertSame(0, $order->history()->count());
        $this->assertNull($order->fresh()->admin_note);
    }

    public function test_note_only_update_keeps_status_and_records_the_author(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $buyer = $this->makeUserWithRole('customer');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($new, $buyer, 'AG-NOTE-AUTHOR');

        Livewire::actingAs($admin)->test(OrderShow::class, ['orderId' => $order->id])
            ->set('form.comment', '  Customer called about delivery.  ')
            ->call('updateStatus')
            ->assertHasNoErrors()
            ->assertSet('form.comment', '');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status_id' => $new->id,
            'admin_note' => 'Customer called about delivery.',
            'updated_by' => $admin->id,
        ]);
        $history = $order->history()->sole();
        $this->assertSame($admin->id, $history->changed_by);
        $this->assertFalse($history->payload['status_changed']);
        $this->assertSame('admin', $history->payload['origin']);
    }

    public function test_repeated_quick_status_keeps_original_paid_date_and_does_not_duplicate_history(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true);
        $order = $this->createOrder($new, $admin, 'AG-QUICK-REPEAT');

        $component = Livewire::actingAs($admin)->test(OrderShow::class, ['orderId' => $order->id])
            ->call('quickStatusByCode', 'paid')->assertHasNoErrors();
        $paidAt = $order->fresh()->paid_at;

        $this->travel(1)->hours();
        $component->call('quickStatusByCode', 'paid')->assertHasNoErrors()
            ->assertDispatched('notify', type: 'info');

        $this->assertSame($paid->id, $order->fresh()->status_id);
        $this->assertTrue($paidAt->equalTo($order->fresh()->paid_at));
        $this->assertSame(1, $order->history()->count());
        $this->assertSame('quick_action', $order->history()->sole()->payload['origin']);
    }

    public function test_unavailable_quick_status_does_not_change_order(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true);
        $paid->update(['is_active' => false]);
        $order = $this->createOrder($new, $admin, 'AG-QUICK-UNAVAILABLE');

        foreach (['paid', 'does-not-exist'] as $code) {
            Livewire::actingAs($admin)->test(OrderShow::class, ['orderId' => $order->id])
                ->call('quickStatusByCode', $code)
                ->assertHasNoErrors()
                ->assertDispatched('notify', type: 'warning');
        }

        $this->assertSame($new->id, $order->fresh()->status_id);
        $this->assertSame(0, $order->history()->count());
    }

    public function test_internal_tag_validation_and_deduplication_preserve_other_payload(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($new, $admin, 'AG-TAG-VALIDATION');
        $order->update(['payload' => ['import' => ['legacy_id' => 123]]]);

        foreach ([' ', '<script>', str_repeat('a', 41)] as $tag) {
            Livewire::actingAs($admin)->test(OrderShow::class, ['orderId' => $order->id])
                ->set('tagInput', $tag)->call('addInternalTag')->assertHasErrors(['tagInput']);
        }

        Livewire::actingAs($admin)->test(OrderShow::class, ['orderId' => $order->id])
            ->set('tagInput', '  priority  ')->call('addInternalTag')
            ->set('tagInput', 'priority')->call('addInternalTag')
            ->assertHasNoErrors()->assertSet('tagInput', '');

        $this->assertSame(['priority'], $order->fresh()->payload['internal_tags']);
        $this->assertSame(123, $order->fresh()->payload['import']['legacy_id']);
    }

    public function test_order_filters_combine_search_status_and_inclusive_dates(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $paid = $this->createStatus(code: 'paid', name: 'Paid', isPaid: true);

        foreach ([
            ['AG-FILTER-START', $new, '2026-10-01 00:00:00'],
            ['AG-FILTER-END', $new, '2026-10-07 23:59:59'],
            ['AG-FILTER-PAID', $paid, '2026-10-03 12:00:00'],
            ['AG-FILTER-OLD', $new, '2026-09-30 23:59:59'],
            ['OTHER-ORDER', $new, '2026-10-03 12:00:00'],
        ] as [$number, $status, $placedAt]) {
            $this->createOrder($status, $admin, $number)->update(['placed_at' => $placedAt]);
        }

        Livewire::actingAs($admin)->test(OrderManager::class)
            ->set('search', 'AG-FILTER')
            ->set('status', (string) $new->id)
            ->set('dateFrom', '2026-10-01')
            ->set('dateTo', '2026-10-07')
            ->assertSee('AG-FILTER-START')->assertSee('AG-FILTER-END')
            ->assertDontSee('AG-FILTER-PAID')->assertDontSee('AG-FILTER-OLD')->assertDontSee('OTHER-ORDER');
    }

    public function test_sorting_and_filter_changes_reset_order_pagination(): void
    {
        app(SystemSettingsService::class)->put('admin_items_per_page', 5);
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        foreach (range(1, 6) as $index) {
            $this->createOrder($new, $admin, 'AG-PAGE-'.$index);
        }

        $component = Livewire::actingAs($admin)->test(OrderManager::class)
            ->call('setPage', 2, 'adminOrdersPage')
            ->assertSet('paginators.adminOrdersPage', 2)
            ->call('sort', 'order_number')
            ->assertSet('sortBy', 'order_number')->assertSet('sortDir', 'asc')
            ->assertSet('paginators.adminOrdersPage', 1)
            ->assertSee('AG-PAGE-1')->assertDontSee('AG-PAGE-6')
            ->call('sort', 'order_number')->assertSet('sortDir', 'desc')
            ->assertSee('AG-PAGE-6')->assertDontSee('AG-PAGE-1');

        foreach (['search' => 'AG-PAGE', 'status' => (string) $new->id, 'dateFrom' => '2026-01-01', 'dateTo' => '2026-12-31'] as $field => $value) {
            $component->call('setPage', 2, 'adminOrdersPage')->set($field, $value)
                ->assertSet('paginators.adminOrdersPage', 1);
        }

        $component->call('sort', 'invalid_column')->assertSet('sortBy', 'order_number');
    }

    public function test_admin_receives_not_found_for_missing_order_pages_and_warning_for_missing_delete(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->actingAs($admin)->get('/admin/orders/999999/show')->assertNotFound();
        $this->get('/admin/orders/999999/invoice')->assertNotFound();
        Livewire::actingAs($admin)->test(OrderManager::class)
            ->call('delete', 999999)->assertHasNoErrors()->assertDispatched('notify', type: 'warning');
    }

    public function test_order_id_cannot_be_changed_from_the_browser(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($new, $admin, 'AG-LOCKED');
        $other = $this->createOrder($new, $admin, 'AG-OTHER');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($admin)->test(OrderShow::class, ['orderId' => $order->id])->set('orderId', $other->id);
    }

    public function test_order_sort_column_cannot_be_changed_from_the_browser(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($admin)->test(OrderManager::class)->set('sortBy', 'missing_column');
    }

    public function test_admin_gls_send_success_returns_to_order_with_confirmation(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($new, $admin, 'AG-GLS-SEND');
        $this->mock(GlsShipmentService::class)->shouldReceive('send')->once()
            ->withArgs(fn (Order $sentOrder, int $authorId): bool => $sentOrder->is($order) && $authorId === $admin->id)
            ->andReturn(['parcel_number' => 'GLS-12345']);

        $this->actingAs($admin)->from('/admin/orders/'.$order->id.'/show')
            ->post('/admin/orders/'.$order->id.'/gls/send')
            ->assertRedirect('/admin/orders/'.$order->id.'/show')
            ->assertSessionHas('notify.type', 'success')
            ->assertSessionHas('notify.message', 'GLS naljepnica je generirana. Broj paketa: GLS-12345');
    }

    public function test_gls_send_failure_returns_an_actionable_message_without_server_error(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($new, $admin, 'AG-GLS-FAILURE');
        $this->mock(GlsShipmentService::class)->shouldReceive('send')->once()
            ->andThrow(new \RuntimeException('GLS service is temporarily unavailable.'));

        $this->actingAs($admin)->from('/admin/orders/'.$order->id.'/show')
            ->post('/admin/orders/'.$order->id.'/gls/send')
            ->assertRedirect('/admin/orders/'.$order->id.'/show')
            ->assertSessionHas('notify.type', 'error')
            ->assertSessionHas('notify.message', 'GLS service is temporarily unavailable.');
        $this->assertSame($new->id, $order->fresh()->status_id);
    }

    public function test_gls_label_failure_returns_to_order_without_server_error(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $new = $this->createStatus(code: 'new', name: 'New', isDefault: true);
        $order = $this->createOrder($new, $admin, 'AG-GLS-LABEL-FAILURE');
        $this->mock(GlsShipmentService::class)->shouldReceive('downloadLabel')->once()
            ->andThrow(new \RuntimeException('The shipping label has not been created.'));

        $this->actingAs($admin)->from('/admin/orders/'.$order->id.'/show')
            ->get('/admin/orders/'.$order->id.'/gls/label')
            ->assertRedirect('/admin/orders/'.$order->id.'/show')
            ->assertSessionHas('notify.type', 'error')
            ->assertSessionHas('notify.message', 'The shipping label has not been created.');
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
