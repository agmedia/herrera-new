<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Dashboard\Overview;
use App\Livewire\Admin\Sales\Order\Manager as Orders;
use App\Livewire\Admin\Sales\Order\Show as OrderDetail;
use App\Livewire\Admin\User\B2BAccountManager;
use App\Livewire\Admin\User\B2BUserProfileEditor;
use App\Livewire\Admin\User\Form as CustomerForm;
use App\Livewire\Admin\User\Manager as Customers;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Analytics\CustomerPurchaseStatistics;
use App\Services\Integrations\Gls\GlsShipmentService;
use App\Services\User\CustomerImpersonationService;
use App\Services\User\StaffImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class OrderManagerAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_dashboard_and_statistics_only_include_assigned_customers_and_orders(): void
    {
        $manager = $this->manager();
        $assigned = $this->customer('Assigned Buyer');
        $other = $this->customer('Other Buyer');
        $this->assign($manager, $assigned);
        $ownOrder = $this->order($assigned, 'MANAGER-OWN', 25);
        $this->order($other, 'MANAGER-OTHER', 900)->update(['currency_code' => 'USD']);
        $this->order(null, 'MANAGER-GUEST', 700);

        Livewire::actingAs($manager)->test(Orders::class)
            ->assertSee('MANAGER-OWN')->assertDontSee('MANAGER-OTHER')->assertDontSee('MANAGER-GUEST')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        Livewire::actingAs($manager)->test(Customers::class)
            ->assertSee('Assigned Buyer')->assertDontSee('Other Buyer')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        Livewire::actingAs($manager)->test(Overview::class)
            ->assertViewHas('statistics', fn ($stats) => $stats['orders'] === 1 && $stats['order_value'] === 25.0)
            ->assertViewHas('recentOrders', fn ($orders) => $orders->modelKeys() === [$ownOrder->id]);

        $statistics = app(CustomerPurchaseStatistics::class);
        $this->assertSame(['EUR'], $statistics->currencies()->all());
        $this->assertSame(1, $statistics->summary([])['orders']);
        $this->assertSame([$assigned->id], $statistics->customers([])->pluck('orders.user_id')->all());
        $this->actingAs($manager)->get(route('admin.users.statistics', ['user_id' => $other->id]))->assertForbidden();
    }

    public function test_empty_assignments_expose_no_customers_or_orders_and_admins_remain_unrestricted(): void
    {
        $manager = $this->manager();
        $customer = $this->customer('Unassigned Buyer');
        $order = $this->order($customer, 'UNASSIGNED');
        $this->actingAs($manager);
        $access = app(OrderManagerAccess::class);
        $this->assertSame(0, $access->scopeCustomers(User::query())->count());
        $this->assertSame(0, $access->scopeOrders(Order::query())->count());
        $this->get(route('admin.orders.show', $order))->assertForbidden();
        $this->get(route('admin.users.show', $customer))->assertForbidden();

        $admin = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('admin')->to($admin);
        Bouncer::assign('order_manager')->to($admin);
        $this->assertFalse($access->isRestricted($admin));
        $this->assertSame(1, $access->scopeOrders(Order::query(), $admin)->count());
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk();
    }

    public function test_foreign_order_pages_invoices_and_gls_are_blocked_before_external_actions(): void
    {
        $manager = $this->manager();
        $own = $this->customer('Own Buyer');
        $this->assign($manager, $own);
        $ownOrder = $this->order($own, 'OWN-DIRECT');
        $otherOrder = $this->order($this->customer('Foreign Buyer'), 'FOREIGN-DIRECT');
        $this->mock(GlsShipmentService::class, function ($mock): void {
            $mock->shouldNotReceive('send');
            $mock->shouldNotReceive('downloadLabel');
        });

        $this->actingAs($manager);
        foreach (['show', 'invoice'] as $page) {
            $this->get(route('admin.orders.'.$page, $ownOrder))->assertOk();
            $this->get(route('admin.orders.'.$page, $otherOrder))->assertForbidden();
        }
        $this->post(route('admin.orders.gls.send', $otherOrder))->assertForbidden();
        $this->get(route('admin.orders.gls.label', $otherOrder))->assertForbidden();
        Livewire::actingAs($manager)->test(OrderDetail::class, ['orderId' => $otherOrder->id])->assertForbidden();
        Livewire::actingAs($manager)->test(Orders::class)->call('delete', $otherOrder->id)->assertForbidden();
        $this->assertModelExists($otherOrder);
    }

    public function test_status_updates_require_current_assignment_even_after_component_was_opened(): void
    {
        $manager = $this->manager();
        $customer = $this->customer('Status Buyer');
        $this->assign($manager, $customer);
        $order = $this->order($customer, 'STATUS-OWN');
        $paid = OrderStatus::query()->create(['code' => 'paid', 'name' => 'Paid', 'is_active' => true, 'is_paid' => true]);
        $component = Livewire::actingAs($manager)->test(OrderDetail::class, ['orderId' => $order->id]);
        $component->set('form.status_id', $paid->id)->call('updateStatus')->assertDispatched('notify');
        $this->assertSame($paid->id, $order->fresh()->status_id);

        DB::table('admin_customer_assignments')->where('admin_user_id', $manager->id)->delete();
        $component->call('addInternalTag')->assertForbidden();
        $this->assertSame([], $order->fresh()->payload['internal_tags'] ?? []);
    }

    public function test_customer_profile_edits_preserve_login_credentials_and_roles(): void
    {
        $manager = $this->manager();
        $customer = $this->customer('Editable Buyer');
        $this->assign($manager, $customer);
        // Staff and customer identities may legitimately share the same email.
        User::factory()->create(['account_type' => 'staff', 'email' => $customer->email]);
        $password = $customer->password;
        $verified = $customer->email_verified_at;

        Livewire::actingAs($manager)->test(CustomerForm::class, ['userId' => $customer->id])
            ->assertDontSee('wire:model="form.password"', false)
            ->assertDontSee('wire:model="form.role"', false)
            ->set('form.name', 'Updated Buyer')->set('form.profile.phone', '+385911234567')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.users'));

        $customer->refresh();
        $this->assertSame('Updated Buyer', $customer->name);
        $this->assertSame('+385911234567', $customer->profile->phone);
        $this->assertSame($password, $customer->password);
        $this->assertTrue($customer->email_verified_at->equalTo($verified));
        $this->assertSame(['customer'], $customer->roles->pluck('name')->all());
    }

    #[DataProvider('protectedFields')]
    public function test_forged_customer_security_changes_are_rejected(string $field, mixed $value): void
    {
        $manager = $this->manager();
        $customer = $this->customer('Protected Buyer');
        $this->assign($manager, $customer);
        $original = $customer->refresh()->getAttributes();

        Livewire::actingAs($manager)->test(CustomerForm::class, ['userId' => $customer->id])
            ->set('form.'.$field, $value)->call('save')->assertForbidden();
        $customer->refresh();
        foreach (['email', 'password', 'email_verified_at', 'account_type', 'admin_username', 'admin_login_enabled'] as $attribute) {
            $this->assertSame($original[$attribute], $customer->getAttributes()[$attribute]);
        }
        $this->assertTrue($customer->isA('customer'));
        $this->assertFalse($customer->isA('admin'));
    }

    public static function protectedFields(): array
    {
        return [['role', 'admin'], ['password', 'Changed-password123!'], ['email_verified', false], ['email', 'takeover@example.test']];
    }

    public function test_foreign_customers_and_assigned_staff_cannot_be_opened_or_impersonated(): void
    {
        $manager = $this->manager();
        $foreign = $this->customer('Foreign Customer');
        $staff = User::factory()->create(['account_type' => 'staff']);
        $staffWithRole = $this->customer('Role-only Staff');
        Bouncer::assign('admin')->to($staffWithRole);
        $this->assign($manager, $staff);
        $this->assign($manager, $staffWithRole);
        $this->actingAs($manager);

        foreach ([$foreign, $staff, $staffWithRole] as $target) {
            $this->get(route('admin.users.show', $target))->assertForbidden();
            $this->get(route('admin.users.edit', $target))->assertForbidden();
            $this->post(route('admin.users.impersonate', $target))->assertForbidden();
            Livewire::actingAs($manager)->test(CustomerForm::class, ['userId' => $target->id])->assertForbidden();
            Livewire::actingAs($manager)->test(B2BUserProfileEditor::class, ['userId' => $target->id])->assertForbidden();
        }
        $this->assertSame(0, app(OrderManagerAccess::class)->scopeCustomers(User::query(), $manager)->count());
    }

    public function test_impersonation_only_accepts_assigned_customers(): void
    {
        $manager = $this->manager();
        $customer = $this->customer('Assigned Impersonation Buyer');
        $this->assign($manager, $customer);

        $this->actingAs($manager)->post(route('admin.users.impersonate', $customer))
            ->assertRedirect(route('account.dashboard'))
            ->assertSessionHas(CustomerImpersonationService::SESSION_KEY.'.admin_id', $manager->id);
        $this->assertAuthenticatedAs($customer);
    }

    public function test_staff_preview_cannot_nest_a_customer_impersonation(): void
    {
        $manager = $this->manager();
        $customer = $this->customer('Preview Buyer');
        $this->assign($manager, $customer);

        $this->actingAs($manager)->withSession([
            StaffImpersonationService::SESSION_KEY => ['admin_id' => 999, 'staff_id' => $manager->id],
        ])->post(route('admin.users.impersonate', $customer))->assertStatus(409);

        $this->assertAuthenticatedAs($manager);
        $this->assertFalse(app(CustomerImpersonationService::class)->canStart($manager, $customer));
    }

    public function test_b2b_account_list_and_selection_use_customer_assignments(): void
    {
        $manager = $this->manager();
        $customer = $this->customer('Assigned B2B Buyer');
        $this->assign($manager, $customer);
        $own = B2BAccount::query()->create(['user_id' => $customer->id, 'company_name' => 'Own B2B Company', 'oib' => '12345678901', 'status' => 'pending']);
        $foreign = B2BAccount::query()->create(['user_id' => $this->customer('Foreign B2B Buyer')->id, 'company_name' => 'Foreign B2B Company', 'oib' => '10987654321', 'status' => 'pending']);

        Livewire::actingAs($manager)->test(B2BAccountManager::class)
            ->assertSee('Own B2B Company')->assertDontSee('Foreign B2B Company')
            ->call('selectAccount', $own->id)->assertSet('selectedId', $own->id)
            ->call('selectAccount', $foreign->id)->assertForbidden();
    }

    public function test_order_manager_role_filter_includes_staff_and_only_enabled_managers_have_preview_actions(): void
    {
        $admin = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('superadmin')->to($admin);
        $enabled = $this->manager();
        $disabled = $this->manager();
        $disabled->forceFill(['admin_login_enabled' => false])->save();
        $staffWithoutRole = User::factory()->create(['account_type' => 'staff']);
        $this->customer('Filter Customer');
        $this->assertFalse(app(CustomerImpersonationService::class)->canStart($admin, $staffWithoutRole));

        Livewire::actingAs($admin)->test(Customers::class)
            ->assertSee('value="order_manager"', false)
            ->set('role', 'order_manager')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2)
            ->assertSee(route('admin.users.staff-impersonation.start', $enabled), false)
            ->assertDontSee(route('admin.users.staff-impersonation.start', $disabled), false);
    }

    public function test_privileged_role_assignment_promotes_identity_to_enabled_staff(): void
    {
        $admin = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('admin')->to($admin);
        $customer = $this->customer('Promoted Manager');
        $customer->forceFill(['admin_login_enabled' => false])->save();

        Livewire::actingAs($admin)->test(CustomerForm::class, ['userId' => $customer->id])
            ->set('form.role', 'order_manager')->call('save')->assertHasNoErrors();
        $customer->refresh();
        $this->assertSame('staff', $customer->account_type);
        $this->assertTrue($customer->admin_login_enabled);
        $this->assertTrue($customer->isA('order_manager'));
    }

    private function manager(): User
    {
        Bouncer::role()->firstOrCreate(['name' => 'order_manager']);
        Bouncer::allow('order_manager')->to(['admin.access', 'dashboard.view', 'sales.orders.view', 'sales.orders.update', 'sales.orders.invoice.view', 'users.list.view', 'users.profile.update']);
        $manager = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('order_manager')->to($manager);

        return $manager;
    }

    private function customer(string $name): User
    {
        $customer = User::factory()->create(['name' => $name, 'account_type' => 'customer']);
        Bouncer::assign('customer')->to($customer);

        return $customer;
    }

    private function assign(User $manager, User $customer): void
    {
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $manager->id, 'customer_user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function order(?User $customer, string $number, float $total = 50): Order
    {
        return Order::query()->create(['user_id' => $customer?->id, 'order_number' => $number, 'customer_name' => $customer?->name ?? 'Guest', 'customer_email' => $customer?->email ?? 'guest@example.test', 'grand_total' => $total, 'item_qty' => 1, 'placed_at' => now()]);
    }
}
