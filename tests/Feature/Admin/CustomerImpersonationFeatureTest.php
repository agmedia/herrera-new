<?php

namespace Tests\Feature\Admin;

use App\Livewire\Actions\Logout;
use App\Models\Sales\Order\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CustomerImpersonationFeatureTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('administratorRoles')]
    public function test_authorized_administrator_can_view_the_customer_account_and_only_their_orders(string $role): void
    {
        $admin = $this->user($role);
        $customer = $this->user('customer');
        $otherCustomer = $this->user('customer');
        $order = $this->order($customer, 'CUSTOMER-PREVIEW-1');
        $otherOrder = $this->order($otherCustomer, 'OTHER-CUSTOMER-1');

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate', $customer))
            ->assertRedirect(route('account.dashboard'))
            ->assertSessionHas('admin.customer_impersonation.admin_id', $admin->id)
            ->assertSessionHas('admin.customer_impersonation.customer_id', $customer->id);

        $this->assertAuthenticatedAs($customer);
        $this->get(route('account.dashboard'))->assertOk()->assertSee($customer->name);
        $this->get(route('account.orders'))->assertOk()
            ->assertSee($order->order_number)
            ->assertDontSee($otherOrder->order_number);
        $this->get(route('account.orders.show', $order->order_number))->assertOk();
        $this->get(route('account.orders.show', $otherOrder->order_number))->assertNotFound();
        $this->get(route('admin.users'))->assertForbidden();
    }

    public function test_customer_cannot_start_a_customer_preview(): void
    {
        $customer = $this->user('customer');
        $target = $this->user('customer');

        $this->actingAs($customer)->post(route('admin.users.impersonate', $target))
            ->assertForbidden()
            ->assertSessionMissing('admin.customer_impersonation');

        $this->assertAuthenticatedAs($customer);
    }

    #[DataProvider('requiredAbilities')]
    public function test_administrator_missing_a_required_ability_cannot_start_a_preview(string $ability): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        Bouncer::forbid($admin)->to($ability);
        Bouncer::refreshFor($admin);

        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer))
            ->assertForbidden()
            ->assertSessionMissing('admin.customer_impersonation');

        $this->assertAuthenticatedAs($admin);
    }

    #[DataProvider('privilegedTargets')]
    public function test_even_superadmin_cannot_impersonate_a_privileged_account(string $role): void
    {
        $admin = $this->user('superadmin');
        $target = $this->user($role === 'admin_ability' ? 'customer' : $role);
        if ($role === 'admin_ability') {
            Bouncer::allow($target)->to('admin.access');
            Bouncer::refreshFor($target);
        }

        $this->actingAs($admin)->post(route('admin.users.impersonate', $target))
            ->assertForbidden()
            ->assertSessionMissing('admin.customer_impersonation');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_an_administrator_cannot_start_a_preview_of_themselves(): void
    {
        $admin = $this->user('superadmin');

        $this->actingAs($admin)->post(route('admin.users.impersonate', $admin))
            ->assertForbidden()
            ->assertSessionMissing('admin.customer_impersonation');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_customer_preview_cannot_be_nested(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        $otherCustomer = $this->user('customer');

        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $this->post(route('admin.users.impersonate', $otherCustomer))->assertForbidden()
            ->assertSessionHas('admin.customer_impersonation.admin_id', $admin->id)
            ->assertSessionHas('admin.customer_impersonation.customer_id', $customer->id);

        $this->assertAuthenticatedAs($customer);
    }

    public function test_start_isolates_and_stop_restores_the_original_cart_checkout_and_frontend_state(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        $adminFront = [
            'cart' => [
                'items' => ['41:0' => ['product_id' => 41, 'product_option_value_id' => null, 'quantity' => 3]],
                'coupon_code' => 'ADMIN-COUPON',
            ],
            'checkout' => ['last_order_id' => 25, 'last_order_number' => 'ADMIN-ORDER-25'],
            'quick_order' => ['selected' => [41]],
        ];

        $this->actingAs($admin)->withSession([
            'front' => $adminFront,
            'auth.password_confirmed_at' => now()->timestamp,
            'url.intended' => route('admin.users'),
        ])->post(route('admin.users.impersonate', $customer))
            ->assertRedirect()
            ->assertSessionMissing('front.cart')
            ->assertSessionMissing('front.checkout')
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertSessionMissing('url.intended')
            ->assertSessionHas('admin.customer_impersonation.admin_front', $adminFront);

        $this->withSession([
            'front.cart.items' => ['87:0' => ['product_id' => 87, 'product_option_value_id' => null, 'quantity' => 6]],
            'front.cart.coupon_code' => 'CUSTOMER-COUPON',
            'front.checkout.last_order_id' => 88,
        ])->post(route('front.impersonation.stop'))
            ->assertRedirect(route('admin.users.show', $customer))
            ->assertSessionMissing('admin.customer_impersonation')
            ->assertSessionHas('front', $adminFront);

        $this->assertAuthenticatedAs($admin);
    }

    public function test_customer_cart_is_discarded_when_administrator_had_no_cart(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');

        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $this->withSession([
            'front.cart.items' => ['87:0' => ['product_id' => 87, 'product_option_value_id' => null, 'quantity' => 6]],
            'front.checkout.last_order_id' => 88,
        ])->post(route('front.impersonation.stop'))
            ->assertRedirect()
            ->assertSessionMissing('front.cart')
            ->assertSessionMissing('front.checkout');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_http_logout_during_customer_preview_returns_to_administrator(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        $adminCart = ['items' => ['41:0' => ['product_id' => 41, 'product_option_value_id' => null, 'quantity' => 3]]];

        $this->actingAs($admin)->withSession(['front.cart' => $adminCart])
            ->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $this->post(route('logout'))
            ->assertRedirect(route('admin.users.show', $customer))
            ->assertSessionMissing('admin.customer_impersonation')
            ->assertSessionHas('front.cart', $adminCart);

        $this->assertAuthenticatedAs($admin);
    }

    public function test_livewire_logout_during_customer_preview_restores_administrator(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');

        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        app(Logout::class)();

        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('admin.customer_impersonation'));
    }

    public function test_navigation_logout_during_customer_preview_restores_administrator_and_their_cart(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        $adminCart = ['items' => ['41:0' => ['product_id' => 41, 'product_option_value_id' => null, 'quantity' => 3]]];

        $this->actingAs($admin)->withSession(['front.cart' => $adminCart])
            ->post(route('admin.users.impersonate', $customer))->assertRedirect();

        Volt::test('layout.navigation')->call('logout')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.users.show', $customer));

        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('admin.customer_impersonation'));
        $this->assertSame($adminCart, session()->get('front.cart'));
    }

    public function test_start_and_stop_do_not_rotate_either_users_remember_token(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        $admin->setRememberToken('original-administrator-token');
        $customer->setRememberToken('original-customer-token');
        $admin->save();
        $customer->save();

        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $this->assertSame('original-administrator-token', $admin->fresh()->getRememberToken());
        $this->assertSame('original-customer-token', $customer->fresh()->getRememberToken());

        $this->post(route('front.impersonation.stop'))->assertRedirect();
        $this->assertSame('original-administrator-token', $admin->fresh()->getRememberToken());
        $this->assertSame('original-customer-token', $customer->fresh()->getRememberToken());
    }

    public function test_stop_stays_available_for_customer_without_approved_b2b_account(): void
    {
        config(['commerce.b2b_only' => true]);
        $admin = $this->user('admin');
        $customer = $this->user('customer');

        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $this->post(route('front.impersonation.stop'))
            ->assertRedirect(route('admin.users.show', $customer))
            ->assertSessionMissing('admin.customer_impersonation');

        $this->assertAuthenticatedAs($admin);
    }

    #[DataProvider('unavailableAdministrators')]
    public function test_stop_logs_out_if_the_originating_administrator_has_become_unavailable(string $reason): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');

        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        if ($reason === 'deleted') {
            $admin->delete();
        } else {
            Bouncer::forbid($admin)->to('admin.access');
            Bouncer::refreshFor($admin);
        }

        $this->post(route('front.impersonation.stop'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('admin.customer_impersonation')
            ->assertSessionMissing('front');

        $this->assertGuest();
    }

    public function test_stop_without_a_preview_does_not_change_the_customer_identity(): void
    {
        $customer = $this->user('customer');

        $this->actingAs($customer)->post(route('front.impersonation.stop'))->assertStatus(409);

        $this->assertAuthenticatedAs($customer);
    }

    public function test_start_and_stop_are_audited_as_the_administrator_acting_on_the_customer(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');

        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $this->post(route('front.impersonation.stop'))->assertRedirect();

        $events = Activity::query()->where('log_name', 'customer-support')->orderBy('id')->get();
        $this->assertSame(['customer_impersonation.started', 'customer_impersonation.stopped'], $events->pluck('event')->all());
        foreach ($events as $event) {
            $this->assertSame(User::class, $event->causer_type);
            $this->assertSame($admin->id, $event->causer_id);
            $this->assertSame(User::class, $event->subject_type);
            $this->assertSame($customer->id, $event->subject_id);
        }
    }

    public function test_administrator_without_sales_access_can_view_customer_profile_without_order_reports(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        $order = $this->order($customer, 'PRIVATE-CUSTOMER-SALES-1');
        Bouncer::forbid($admin)->to('sales.orders.view');
        Bouncer::refreshFor($admin);

        $this->actingAs($admin)->get(route('admin.users.show', $customer))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertDontSee($order->order_number)
            ->assertDontSee('125.50 €')
            ->assertDontSee('Statistika kupnje')
            ->assertDontSee('data-customer-purchase-currency', false);
    }

    public static function administratorRoles(): array
    {
        return [['admin'], ['superadmin']];
    }

    public static function requiredAbilities(): array
    {
        return [['admin.access'], ['users.list.view'], ['users.profile.update']];
    }

    public static function privilegedTargets(): array
    {
        return [['superadmin'], ['admin'], ['editor'], ['admin_ability']];
    }

    public static function unavailableAdministrators(): array
    {
        return [['deleted'], ['access_revoked']];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }

    private function order(User $customer, string $number): Order
    {
        return Order::query()->create([
            'order_number' => $number,
            'user_id' => $customer->id,
            'source' => 'web',
            'locale' => 'hr',
            'currency_code' => 'EUR',
            'currency_rate' => 1,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'grand_total' => 125.50,
            'placed_at' => now(),
        ]);
    }
}
