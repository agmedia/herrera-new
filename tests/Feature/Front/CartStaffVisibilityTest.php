<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CartStaffVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('salesRoles')]
    public function test_sales_staff_can_see_separate_own_and_supplier_stock(string $role): void
    {
        $staff = $this->staff($role);
        $product = $this->product();
        $this->actingAs($staff);
        $this->add($product);

        $this->get(route('cart.index'))->assertOk()
            ->assertSee('data-cart-staff-stock', false)
            ->assertSee('Vlastita zaliha')->assertSee('Zaliha dobavljača')
            ->assertViewHas('staffStockByLine', [$product->id.':0' => ['own' => 7, 'supplier' => 19]]);
    }

    public static function salesRoles(): array
    {
        return [['admin'], ['superadmin'], ['order_manager']];
    }

    public function test_variant_uses_its_own_stock_without_claiming_product_supplier_stock_for_that_variant(): void
    {
        $product = $this->product();
        $option = Option::query()->create(['code' => 'stock-size', 'type' => 'radio', 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'stock-xl', 'is_active' => true]);
        $variant = $product->optionValues()->create([
            'option_value_id' => $value->id, 'sku' => 'STOCK-XL', 'mode' => 'variant',
            'stock_qty' => 3, 'is_active' => true, 'combination_hash' => hash('sha256', 'STOCK-XL'),
        ]);
        $this->actingAs($this->staff('admin'))->postJson(route('cart.items.store'), [
            'product_id' => $product->id, 'product_option_value_id' => $variant->id, 'quantity' => 1,
        ])->assertOk();

        $this->get(route('cart.index'))->assertOk()
            ->assertViewHas('staffStockByLine', [$product->id.':'.$variant->id => ['own' => 3, 'supplier' => null]])
            ->assertSee('Zaliha dobavljača nije dostupna po varijanti.');
    }

    public function test_customer_and_guest_do_not_receive_staff_stock_markup_or_data(): void
    {
        $product = $this->product();
        $this->add($product);
        $this->assertPrivateStock();

        $this->actingAs(User::factory()->create());
        $this->assertPrivateStock();
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk()->assertJsonMissingPath('staffStockByLine')->assertJsonMissingPath('stock');
    }

    public function test_editor_or_disabled_staff_cannot_view_sales_stock(): void
    {
        $product = $this->product();
        $this->add($product);
        $this->actingAs($this->staff('editor'));
        $this->assertPrivateStock();

        $admin = $this->staff('admin');
        $admin->forceFill(['admin_login_enabled' => false])->save();
        $this->actingAs($admin);
        $this->assertPrivateStock();
    }

    public function test_assigned_salesperson_keeps_stock_visibility_while_impersonating_customer_and_loses_it_when_unassigned(): void
    {
        $manager = $this->staff('order_manager');
        $customer = User::factory()->create();
        DB::table('admin_customer_assignments')->insert([
            'admin_user_id' => $manager->id, 'customer_user_id' => $customer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($manager)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $product = $this->product();
        $this->add($product);

        $this->get(route('cart.index'))->assertOk()->assertSee('data-cart-staff-stock', false);
        DB::table('admin_customer_assignments')->where('admin_user_id', $manager->id)->delete();
        $this->assertPrivateStock();
    }

    public function test_real_b2b_cart_exposes_stock_only_during_authorized_salesperson_customer_preview(): void
    {
        config(['commerce.b2b_only' => true]);
        $manager = $this->staff('order_manager');
        $customer = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'preview-buyer', 'name' => 'Preview buyer', 'is_active' => true]);
        B2BAccount::query()->create([
            'user_id' => $customer->id, 'company_name' => 'Approved preview buyer', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
        ]);
        DB::table('admin_customer_assignments')->insert([
            'admin_user_id' => $manager->id, 'customer_user_id' => $customer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = $this->product();

        $this->actingAs($customer);
        $this->add($product);
        $this->get(route('cart.index'))->assertOk()
            ->assertSee('Artikl za provjeru zalihe')->assertDontSee('data-cart-staff-stock', false)
            ->assertViewHas('lines', fn ($lines): bool => $lines->count() === 1)
            ->assertViewHas('staffStockByLine', []);

        $this->actingAs($manager)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $this->assertAuthenticatedAs($customer);
        $this->add($product);
        $this->get(route('cart.index'))->assertOk()
            ->assertSee('data-cart-staff-stock', false)
            ->assertViewHas('lines', fn ($lines): bool => $lines->count() === 1)
            ->assertViewHas('staffStockByLine', [$product->id.':0' => ['own' => 7, 'supplier' => 19]]);

        $this->post(route('front.impersonation.stop'))->assertRedirect();
        $this->actingAs($customer);
        $this->add($product);
        $this->get(route('cart.index'))->assertOk()
            ->assertSee('Artikl za provjeru zalihe')->assertDontSee('data-cart-staff-stock', false)
            ->assertViewHas('lines', fn ($lines): bool => $lines->count() === 1)
            ->assertViewHas('staffStockByLine', []);
    }

    public function test_customer_preview_rechecks_the_original_administrators_current_permissions(): void
    {
        $admin = $this->staff('admin');
        $customer = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $this->add($this->product());
        $this->get(route('cart.index'))->assertOk()->assertSee('data-cart-staff-stock', false);

        Bouncer::forbid($admin)->to('users.profile.update');
        Bouncer::refreshFor($admin);
        $this->assertPrivateStock();
    }

    public function test_mismatched_impersonation_context_cannot_expose_stock(): void
    {
        $this->add($this->product());
        $customer = User::factory()->create();
        $admin = $this->staff('admin');
        $this->actingAs($customer)->withSession(['admin.customer_impersonation' => [
            'admin_id' => $admin->id, 'customer_id' => $customer->id + 1,
        ]]);
        $this->assertPrivateStock();
    }

    public function test_staff_preview_uses_salespersons_role_and_rechecks_the_original_admin(): void
    {
        $admin = $this->staff('superadmin');
        $manager = $this->staff('order_manager');
        $this->actingAs($admin)->post(route('admin.users.staff-impersonation.start', $manager))->assertRedirect();
        $this->add($this->product());
        $this->get(route('cart.index'))->assertOk()->assertSee('data-cart-staff-stock', false);

        $admin->forceFill(['admin_login_enabled' => false])->save();
        $this->assertPrivateStock();
    }

    public function test_confirmation_mode_is_server_selected_and_cart_counter_is_unchanged(): void
    {
        $product = $this->product();
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2, 'confirmation' => 'notice'])
            ->assertOk()->assertJsonPath('confirmation', 'modal')->assertJsonPath('summary.item_qty', 2);

        config(['commerce.b2b_only' => true]);
        $customer = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'cart-buyer', 'name' => 'Cart buyer', 'is_active' => true]);
        B2BAccount::query()->create(['user_id' => $customer->id, 'company_name' => 'Cart buyer', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);
        $this->actingAs($customer)->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1, 'confirmation' => 'modal'])
            ->assertOk()->assertJsonPath('confirmation', 'notice')->assertJsonPath('summary.item_qty', 3);
    }

    private function assertPrivateStock(): void
    {
        $this->get(route('cart.index'))->assertOk()->assertDontSee('data-cart-staff-stock', false)
            ->assertDontSee('Vlastita zaliha')->assertDontSee('Zaliha dobavljača')
            ->assertViewHas('staffStockByLine', []);
    }

    private function add(Product $product): void
    {
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertOk();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['account_type' => 'staff', 'admin_login_enabled' => true]);
        Bouncer::assign($role)->to($user);

        return $user;
    }

    private function product(): Product
    {
        $product = Product::query()->create(['code' => 'STAFF-STOCK', 'sku' => 'STAFF-STOCK', 'is_active' => true, 'base_price' => 20, 'stock_qty' => 7, 'supplier_stock_qty' => 19]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Artikl za provjeru zalihe', 'slug' => 'staff-stock']);

        return $product;
    }
}
