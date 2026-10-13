<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Models\User\UserAddress;
use App\Services\Front\CheckoutService;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Settings\SystemSettingsService;
use App\Services\User\CustomerImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class AssignedCustomerOrderingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $first;

    private User $second;

    private User $foreign;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true, 'app.locale' => 'hr', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Herrera', 'store_pricing_prices_include_tax' => false]);
        $this->manager = User::factory()->create(['account_type' => 'staff', 'admin_login_enabled' => true]);
        Bouncer::assign('order_manager')->to($this->manager);
        $this->first = $this->customer('first');
        $this->second = $this->customer('second');
        $this->foreign = $this->customer('foreign');
        foreach ([$this->first, $this->second] as $customer) {
            DB::table('admin_customer_assignments')->insert(['admin_user_id' => $this->manager->id, 'customer_user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $tax = TaxRate::query()->create(['code' => 'ZERO', 'name' => 'Zero', 'rate_type' => 'percent', 'rate' => 0, 'is_active' => true, 'is_default' => true]);
        $this->product = Product::query()->create(['code' => 'ASSIGNED-ORDER', 'sku' => 'ASSIGNED-ORDER', 'is_active' => true, 'base_price' => 100, 'stock_qty' => 20, 'supplier_stock_qty' => 40, 'tax_rate_id' => $tax->id]);
        $this->product->translations()->create(['locale' => 'hr', 'name' => 'Artikl za narudžbu kupca', 'slug' => 'assigned-order']);
        $pricingAdmin = User::factory()->create(['account_type' => 'staff', 'admin_login_enabled' => true]);
        Bouncer::assign('superadmin')->to($pricingAdmin);
        $catalogs = app(PriceCatalogService::class);
        $catalog = $catalogs->createDraft('Assigned customer pricing', $pricingAdmin);
        $catalog->entries()->create(['source_key' => 'base', 'product_id' => $this->product->id, 'kind' => PriceCatalogEntry::BASE, 'price' => 100, 'minimum_quantity' => 1, 'is_active' => true]);
        foreach ([[$this->first, 12.5], [$this->second, 21.75], [$this->foreign, 33.25]] as [$customer, $price]) {
            $catalog->entries()->create(['source_key' => 'group-'.$customer->id, 'product_id' => $this->product->id, 'kind' => PriceCatalogEntry::GROUP, 'customer_group_id' => $customer->b2bAccount->customer_group_id, 'price' => $price, 'minimum_quantity' => 1, 'is_active' => true]);
        }
        $catalogs->activate($catalog, $pricingAdmin);
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 0, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    public function test_salesperson_can_order_for_each_assigned_customer_at_their_own_prices_and_return_to_their_list(): void
    {
        Livewire::actingAs($this->manager)->test(\App\Livewire\Admin\User\Manager::class)
            ->assertSee('Moji kupci')->assertSee('Naruči za kupca')
            ->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->sort()->values()->all() === [$this->first->id, $this->second->id]);
        $staffFront = ['cart' => ['items' => ['staff-draft' => ['product_id' => $this->product->id, 'quantity' => 7]]]];
        foreach ([[$this->first, 12.5], [$this->second, 21.75]] as [$customer, $price]) {
            $email = $customer->email;
            $password = $customer->password;
            $this->actingAs($this->manager)->withSession(['front' => $staffFront])
                ->post(route('admin.users.impersonate', $customer))->assertRedirect(route('shop.index'));
            $this->assertAuthenticatedAs($customer);
            $this->assertSame([], session('front.cart.items', []));
            $startedAt = session(CustomerImpersonationService::SESSION_KEY.'.started_at');
            $this->get(route('shop.index'))->assertOk()->assertSee('Radite za kupca:')->assertSee($customer->companyName());
            $this->postJson(route('cart.items.store'), ['product_id' => $this->product->id, 'quantity' => 2])
                ->assertOk()->assertJsonPath('summary.item_qty', 2);
            $this->get(route('cart.index'))->assertOk()->assertSee('data-cart-staff-stock', false)
                ->assertViewHas('lines', fn ($lines): bool => $lines->count() === 1 && (float) $lines->sole()['unit_price'] === $price);
            $this->get(route('checkout.create'))->assertOk()
                ->assertViewHas('prefill', fn ($prefill): bool => $prefill['email'] === $email && $prefill['billing']['company'] === $customer->companyName());
            $payload = $this->checkoutPayload($customer);
            $payload['customer_email'] = 'order-contact-'.$customer->id.'@example.test';
            $payload['created_by'] = $this->foreign->id;
            $payload['customer_impersonation'] = ['admin_id' => $this->foreign->id];
            $this->postJson(route('checkout.store'), $payload)->assertOk()->assertJsonPath('status', 'ok');
            $order = Order::query()->where('user_id', $customer->id)->sole();
            $this->assertSame($this->manager->id, $order->created_by);
            $this->assertSame($this->manager->id, $order->updated_by);
            $this->assertSame($customer->companyName(), $order->billing_company);
            $this->assertSame($payload['customer_email'], $order->customer_email);
            $this->assertSame($price, (float) $order->items()->sole()->unit_price);
            $this->assertSame($price * 2, (float) $order->grand_total);
            $this->assertSame(['admin_id' => $this->manager->id, 'customer_id' => $customer->id, 'started_at' => $startedAt], $order->payload['customer_impersonation']);
            $this->assertSame($email, $customer->fresh()->email);
            $this->assertSame($password, $customer->fresh()->password);
            $this->post(route('front.impersonation.stop'))->assertRedirect(route('admin.users'))->assertSessionMissing(CustomerImpersonationService::SESSION_KEY)->assertSessionHas('front', $staffFront);
            $this->assertAuthenticatedAs($this->manager);
            $this->get(route('admin.orders.show', $order))->assertOk()
                ->assertSee('Narudžbu unio:')->assertSee($this->manager->name);
        }
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(16, $this->product->fresh()->stock_qty);
    }

    public function test_unassigned_and_unapproved_customers_cannot_be_used_to_place_orders(): void
    {
        $this->actingAs($this->manager)->post(route('admin.users.impersonate', $this->foreign))->assertForbidden();
        $this->assertAuthenticatedAs($this->manager);
        $this->first->b2bAccount()->update(['status' => 'pending']);
        $this->post(route('admin.users.impersonate', $this->first))->assertRedirect(route('shop.index'));
        $this->get(route('shop.index'))->assertOk()->assertDontSee('product-card-current-price', false);
        $this->postJson(route('cart.items.store'), ['product_id' => $this->product->id, 'quantity' => 1])->assertForbidden();
        $this->postJson(route('checkout.store'), $this->checkoutPayload($this->first))->assertForbidden();
        $this->assertSame(0, Order::query()->count());
    }

    #[DataProvider('revocations')]
    public function test_active_delegation_is_rechecked_before_prices_cart_and_ordering(string $reason): void
    {
        $this->actingAs($this->manager)->post(route('admin.users.impersonate', $this->first))->assertRedirect();
        $this->postJson(route('cart.items.store'), ['product_id' => $this->product->id, 'quantity' => 1])->assertOk();
        if ($reason === 'assignment') {
            DB::table('admin_customer_assignments')->where('admin_user_id', $this->manager->id)->where('customer_user_id', $this->first->id)->delete();
        } elseif ($reason === 'login') {
            $this->manager->forceFill(['admin_login_enabled' => false])->save();
        } else {
            Bouncer::forbid($this->manager)->to('users.profile.update');
            Bouncer::refreshFor($this->manager);
        }
        $this->get(route('shop.index'))->assertForbidden()
            ->assertSee('data-customer-impersonation-expired', false)->assertSee(route('front.impersonation.stop'), false);
        $this->get(route('cart.index'))->assertForbidden();
        $this->postJson(route('checkout.store'), $this->checkoutPayload($this->first))->assertForbidden();
        try {
            app(CheckoutService::class)->placeOrder($this->checkoutPayload($this->first), auth()->user());
            $this->fail('Delegated ordering must recheck access even outside the controller.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Auth\Access\AuthorizationException $exception) {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(20, $this->product->fresh()->stock_qty);
        }
        $this->post(route('front.impersonation.stop'))->assertRedirect($reason === 'login' ? route('login') : route('admin.users'));
    }

    public static function revocations(): array
    {
        return [['assignment'], ['login'], ['permission']];
    }

    public function test_delegated_checkout_cannot_change_customer_login_email_through_profile(): void
    {
        $email = $this->first->email;
        $this->actingAs($this->manager)->post(route('admin.users.impersonate', $this->first))->assertRedirect();
        $this->put(route('account.profile.update'), ['name' => $this->first->name, 'email' => 'replacement@example.test'])->assertForbidden();
        $this->assertSame($email, $this->first->fresh()->email);
    }

    public function test_direct_customer_order_has_no_salesperson_attribution(): void
    {
        $this->actingAs($this->first)->postJson(route('cart.items.store'), ['product_id' => $this->product->id, 'quantity' => 1])->assertOk();
        $payload = $this->checkoutPayload($this->first) + ['customer_impersonation' => ['admin_id' => $this->manager->id], 'created_by' => $this->manager->id];
        $this->postJson(route('checkout.store'), $payload)->assertOk();
        $order = Order::query()->sole();
        $this->assertSame($this->first->id, $order->user_id);
        $this->assertSame($this->first->id, $order->created_by);
        $this->assertArrayNotHasKey('customer_impersonation', $order->payload);
    }

    public function test_delegated_checkout_uses_approved_company_data_when_billing_fields_are_empty(): void
    {
        $this->first->addresses()->update(['company' => '', 'oib' => '']);
        $this->actingAs($this->manager)->post(route('admin.users.impersonate', $this->first))->assertRedirect();
        $this->postJson(route('cart.items.store'), ['product_id' => $this->product->id, 'quantity' => 1])->assertOk();
        $this->get(route('checkout.create'))->assertOk()
            ->assertViewHas('prefill', fn ($prefill): bool => $prefill['billing']['company'] === 'Tvrtka first' && $prefill['billing']['oib'] === '12345678901');
    }

    private function customer(string $code): User
    {
        $customer = User::factory()->create(['name' => 'Kontakt '.$code]);
        Bouncer::assign('customer')->to($customer);
        $group = CustomerGroup::query()->create(['code' => 'assigned-'.$code, 'name' => $code, 'is_active' => true]);
        B2BAccount::query()->create(['user_id' => $customer->id, 'company_name' => 'Tvrtka '.$code, 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);
        $customer->profile()->create(['first_name' => 'Kontakt', 'last_name' => $code, 'phone' => '+385911234567']);
        $customer->addresses()->create(['type' => UserAddress::TYPE_BILLING, 'first_name' => 'Kontakt', 'last_name' => $code, 'company' => 'Tvrtka '.$code, 'oib' => '12345678901', 'address_line_1' => 'Testna 1', 'postal_code' => '10000', 'city' => 'Zagreb', 'country_code' => 'HR', 'is_default' => true]);

        return $customer;
    }

    private function checkoutPayload(User $customer): array
    {
        return ['customer_email' => $customer->email, 'customer_phone' => '+385911234567', 'billing_first_name' => 'Kontakt', 'billing_last_name' => 'Test', 'billing_company' => $customer->companyName(), 'billing_oib' => '12345678901', 'billing_address_line_1' => 'Testna 1', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'billing_country_code' => 'HR', 'ship_to_different_address' => false, 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank', 'accept_terms' => true];
    }
}
