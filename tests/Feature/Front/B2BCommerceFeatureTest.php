<?php

namespace Tests\Feature\Front;

use App\Livewire\Admin\User\B2BAccountManager;
use App\Models\Catalog\Pricing\B2BPriceRule;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\ProductGroupPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class B2BCommerceFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if ($this->app?->bound('livewire')) {
            $this->app->make('livewire')->flushState();
        }

        parent::tearDown();
    }

    public function test_business_registration_creates_pending_account_profile_and_billing_address(): void
    {
        CustomerGroup::query()->create([
            'code' => 'retail-default',
            'name' => 'Retail Default',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 1,
        ]);

        $response = $this->post(route('front.auth.b2b-register.store'), [
            'first_name' => 'Ana',
            'last_name' => 'Horvat',
            'email' => 'ana@tvrtka.test',
            'phone' => '+385 1 555 123',
            'company_name' => 'Tvrtka d.o.o.',
            'oib' => '12345678901',
            'address_line_1' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => '1',
        ]);

        $user = User::query()->where('email', 'ana@tvrtka.test')->firstOrFail();

        $response->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('b2b_accounts', [
            'user_id' => $user->id,
            'status' => B2BAccount::STATUS_PENDING,
            'company_name' => 'Tvrtka d.o.o.',
            'oib' => '12345678901',
            'vat_id' => null,
            'address_line_2' => null,
            'customer_group_id' => null,
            'erp_customer_id' => null,
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'company' => 'Tvrtka d.o.o.',
            'oib' => '12345678901',
        ]);
        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id,
            'type' => 'billing',
            'company' => 'Tvrtka d.o.o.',
            'city' => 'Zagreb',
            'vat_id' => null,
            'address_line_2' => null,
        ]);
        $this->assertCount(0, $user->customerGroups);
        $this->assertDatabaseMissing('customer_group_user', [
            'user_id' => $user->id,
        ]);
    }

    public function test_logged_in_b2b_user_is_redirected_from_registration_to_their_account(): void
    {
        $customer = User::factory()->create();
        $this->makeB2BAccount($customer, B2BAccount::STATUS_PENDING);

        $this->actingAs($customer)
            ->get(route('front.auth.b2b-register'))
            ->assertRedirect(route('account.dashboard'));

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertRedirect(route('account.dashboard'));
    }

    public function test_business_registration_validation_is_translated_and_rendered_beside_each_field(): void
    {
        $this->from(route('front.auth.b2b-register'))
            ->post(route('front.auth.b2b-register.store'), [
                'country_code' => '',
            ])
            ->assertRedirect(route('front.auth.b2b-register'))
            ->assertSessionHasErrors([
                'first_name',
                'last_name',
                'email',
                'phone',
                'company_name',
                'oib',
                'address_line_1',
                'postal_code',
                'city',
                'country_code',
                'password',
                'password_confirmation',
                'terms_accepted',
            ]);

        $this->get(route('front.auth.b2b-register'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="b2b-first_name"',
                'id="b2b-first_name-error"',
                'Polje ime je obavezno.',
            ], false)
            ->assertSeeInOrder([
                'id="b2b-password-confirmation"',
                'id="b2b-password-confirmation-error"',
                'Polje potvrda lozinke je obavezno.',
            ], false)
            ->assertSeeInOrder([
                'name="terms_accepted"',
                'id="b2b-terms-error"',
                'Za registraciju morate prihvatiti Opće uvjete korištenja.',
            ], false)
            ->assertDontSee('validation.required')
            ->assertDontSee('<ul class="list-disc space-y-1 pl-5">', false);
    }

    public function test_business_registration_uses_the_checkout_postal_code_autofill_contract(): void
    {
        $this->get(route('front.auth.b2b-register'))
            ->assertOk()
            ->assertSee('data-address-autofill', false)
            ->assertSee('data-address-source="'.asset('front-theme/data/hr-places.json').'"', false)
            ->assertSee('data-address-scope="billing"', false)
            ->assertSee('name="postal_code"', false)
            ->assertSee('data-address-postal', false)
            ->assertSee('name="city"', false)
            ->assertSee('data-address-city', false)
            ->assertSee('name="country_code"', false)
            ->assertSee('data-address-country', false)
            ->assertSee('front-theme/scripts/address-autofill.js', false)
            ->assertSee('PDV ID (OIB)')
            ->assertSee('name="terms_accepted"', false)
            ->assertSee('/page/uvjeti-koristenja', false)
            ->assertDontSee('name="vat_id"', false)
            ->assertDontSee('name="address_line_2"', false);
    }

    public function test_admin_can_approve_request_assign_group_and_store_future_erp_link(): void
    {
        $admin = $this->makeAdmin();
        $customer = User::factory()->create();
        $group = $this->makeGroup('b2b-standard');
        $account = $this->makeB2BAccount($customer, B2BAccount::STATUS_PENDING);

        Livewire::actingAs($admin)
            ->test(B2BAccountManager::class)
            ->call('selectAccount', $account->id)
            ->set('form.status', B2BAccount::STATUS_APPROVED)
            ->set('form.customer_group_id', $group->id)
            ->set('form.erp_customer_id', 'ERP-K-1024')
            ->set('form.erp_company_code', 'TERMOL-HR')
            ->set('form.contract_number', 'UG-2026-44')
            ->set('form.payment_terms_days', 30)
            ->set('form.purchase_order_required', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('b2b_accounts', [
            'id' => $account->id,
            'status' => B2BAccount::STATUS_APPROVED,
            'customer_group_id' => $group->id,
            'erp_customer_id' => 'ERP-K-1024',
            'contract_number' => 'UG-2026-44',
            'payment_terms_days' => 30,
            'purchase_order_required' => true,
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('customer_group_user', [
            'user_id' => $customer->id,
            'customer_group_id' => $group->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.b2b'))
            ->assertOk()
            ->assertSee('B2B zahtjevi i računi');
    }

    public function test_individual_contract_price_overrides_group_price_rule(): void
    {
        $customer = User::factory()->create();
        $group = $this->makeGroup('wholesale');
        $customer->customerGroups()->attach($group);
        $this->makeB2BAccount($customer, B2BAccount::STATUS_APPROVED, $group);
        $product = $this->makeProduct('B2B-PRICE-01', 100);

        B2BPriceRule::query()->create([
            'code' => 'GROUP-20',
            'name' => 'Grupni popust',
            'customer_group_id' => $group->id,
            'calculation_type' => B2BPriceRule::TYPE_PERCENTAGE_DISCOUNT,
            'value' => 20,
            'target_type' => B2BPriceRule::TARGET_ALL,
            'minimum_quantity' => 1,
            'currency_code' => 'EUR',
            'priority' => 10,
            'is_active' => true,
        ]);
        $individual = B2BPriceRule::query()->create([
            'code' => 'CUSTOMER-FIXED-65',
            'name' => 'Ugovorena cijena kupca',
            'user_id' => $customer->id,
            'contract_number' => 'UG-IND-1',
            'calculation_type' => B2BPriceRule::TYPE_FIXED_PRICE,
            'value' => 65,
            'target_type' => B2BPriceRule::TARGET_PRODUCT,
            'minimum_quantity' => 1,
            'currency_code' => 'EUR',
            'priority' => 1,
            'is_active' => true,
        ]);
        $individual->targets()->create([
            'target_type' => B2BPriceRule::TARGET_PRODUCT,
            'target_id' => $product->id,
            'sort_order' => 0,
        ]);

        $resolved = app(ProductGroupPriceResolver::class)->resolve($product, $customer, 1);

        $this->assertSame(65.0, $resolved?->price);
        $this->assertSame($customer->id, $resolved?->user_id);
        $this->assertSame($individual->id, $resolved?->rule_id);
    }

    public function test_only_approved_b2b_customer_can_use_quick_order_by_code_sku_or_barcode(): void
    {
        $pending = User::factory()->create();
        $this->makeB2BAccount($pending, B2BAccount::STATUS_PENDING);
        $this->actingAs($pending)
            ->get(route('account.b2b.quick-order'))
            ->assertForbidden();

        $customer = User::factory()->create();
        $group = $this->makeGroup('approved-b2b');
        $this->makeB2BAccount($customer, B2BAccount::STATUS_APPROVED, $group);
        $productByCode = $this->makeProduct('CODE-100', 15, 'SKU-100', '3850000000100');
        $productByBarcode = $this->makeProduct('CODE-200', 25, 'SKU-200', '3850000000200');

        $this->actingAs($customer)
            ->get(route('account.b2b.quick-order'))
            ->assertOk()
            ->assertSee('B2B brza kupnja');

        $response = $this->actingAs($customer)
            ->post(route('account.b2b.quick-order.store'), [
                'items' => [
                    ['identifier' => $productByCode->code, 'quantity' => 2],
                    ['identifier' => $productByBarcode->barcode, 'quantity' => 3],
                ],
            ]);

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('front.cart.items.'.$productByCode->id.':0.quantity', 2);
        $response->assertSessionHas('front.cart.items.'.$productByBarcode->id.':0.quantity', 3);
    }

    public function test_quick_order_uses_one_ajax_search_and_finds_products_by_name_or_identifier(): void
    {
        $pending = User::factory()->create();
        $this->makeB2BAccount($pending, B2BAccount::STATUS_PENDING);

        $customer = User::factory()->create();
        $group = $this->makeGroup('quick-search');
        $this->makeB2BAccount($customer, B2BAccount::STATUS_APPROVED, $group);
        $product = $this->makeProduct('VENT-550', 125, 'SKU-VENT-550', '3850000550001');
        $product->translations()->where('locale', 'hr')->update([
            'name' => 'Stropni ventilator s rasvjetom',
        ]);

        $this->actingAs($customer)
            ->get(route('account.b2b.quick-order'))
            ->assertOk()
            ->assertSee('data-quick-order-search', false)
            ->assertSee('data-quick-order-lines', false)
            ->assertSee('Pretražujte po nazivu, šifri, SKU-u ili barkodu.')
            ->assertDontSee('data-quick-order-identifier', false);

        $this->actingAs($pending)
            ->getJson(route('account.b2b.quick-order.search', ['q' => 'ventilator']))
            ->assertForbidden();

        $this->actingAs($customer)
            ->getJson(route('account.b2b.quick-order.search', ['q' => 'ventilator']))
            ->assertOk()
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.0.name', 'Stropni ventilator s rasvjetom')
            ->assertJsonPath('items.0.code', 'VENT-550')
            ->assertJsonPath('items.0.sku', 'SKU-VENT-550');

        $this->actingAs($customer)
            ->getJson(route('account.b2b.quick-order.search', ['q' => '3850000550001']))
            ->assertOk()
            ->assertJsonPath('items.0.product_id', $product->id);
    }

    public function test_frequent_and_favorite_products_are_separate_b2b_navigation_pages(): void
    {
        $customer = User::factory()->create();
        $group = $this->makeGroup('b2b-product-lists');
        $this->makeB2BAccount($customer, B2BAccount::STATUS_APPROVED, $group);
        $frequentProduct = $this->makeProduct('FREQUENT-100', 60);
        $favoriteProduct = $this->makeProduct('FAVORITE-100', 80);
        $order = Order::query()->create([
            'order_number' => 'WEB-B2B-LISTS-100',
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
        ]);
        $order->items()->create([
            'product_id' => $frequentProduct->id,
            'sku' => $frequentProduct->sku,
            'code' => $frequentProduct->code,
            'name' => 'Artikl FREQUENT-100',
            'unit_price' => 60,
            'quantity' => 4,
            'line_total' => 240,
        ]);
        $customer->wishlistItems()->create([
            'product_id' => $favoriteProduct->id,
        ]);

        $this->actingAs($customer)
            ->get(route('account.b2b.quick-order'))
            ->assertOk()
            ->assertDontSee('Unos artikala')
            ->assertDontSee('Odaberite proizvod ili konkretnu varijantu iz rezultata pretrage.')
            ->assertDontSee('Artikl FREQUENT-100')
            ->assertDontSee('Artikl FAVORITE-100')
            ->assertSee(route('account.b2b.frequent-products'), false)
            ->assertSee(route('account.b2b.favorite-products'), false);

        $this->actingAs($customer)
            ->get(route('account.b2b.frequent-products'))
            ->assertOk()
            ->assertSee('Često naručivani artikli')
            ->assertSee('Artikl FREQUENT-100')
            ->assertDontSee('Artikl FAVORITE-100')
            ->assertSee(route('account.b2b.quick-order', ['code' => $frequentProduct->sku]), false);

        $this->actingAs($customer)
            ->get(route('account.b2b.favorite-products'))
            ->assertOk()
            ->assertSee('Favoriti')
            ->assertSee('Artikl FAVORITE-100')
            ->assertDontSee('Artikl FREQUENT-100')
            ->assertSee(route('account.b2b.quick-order', ['code' => $favoriteProduct->sku]), false);
    }

    public function test_quick_order_accepts_ajax_selected_product_ids(): void
    {
        $customer = User::factory()->create();
        $group = $this->makeGroup('quick-selected');
        $this->makeB2BAccount($customer, B2BAccount::STATUS_APPROVED, $group);
        $product = $this->makeProduct('SELECTED-100', 42);

        $response = $this->actingAs($customer)
            ->post(route('account.b2b.quick-order.store'), [
                'items' => [[
                    'product_id' => $product->id,
                    'product_option_value_id' => null,
                    'identifier' => $product->sku,
                    'quantity' => 4,
                ]],
            ]);

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('front.cart.items.'.$product->id.':0.quantity', 4);
    }

    public function test_quick_order_draft_is_stored_in_database_and_restored_after_logout_and_login(): void
    {
        $customer = User::factory()->create();
        $group = $this->makeGroup('quick-draft');
        $account = $this->makeB2BAccount($customer, B2BAccount::STATUS_APPROVED, $group);
        $product = $this->makeProduct('DRAFT-100', 42);

        $response = $this->actingAs($customer)
            ->putJson(route('account.b2b.quick-order.draft'), [
                'items' => [[
                    'product_id' => $product->id,
                    'product_option_value_id' => null,
                    'quantity' => 4,
                ]],
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'saved' => true,
                'count' => 1,
            ]);

        $this->assertSame([[
            'product_id' => $product->id,
            'product_option_value_id' => null,
            'quantity' => 4,
        ]], $account->fresh()->quick_order_draft);

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();

        $this->post(route('front.auth.login.store'), [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($customer);

        $this->actingAs($customer)
            ->get(route('account.b2b.quick-order'))
            ->assertOk()
            ->assertSee('Artikl DRAFT-100')
            ->assertSee('"quantity":4', false);
    }

    public function test_removing_all_quick_order_items_clears_the_saved_draft(): void
    {
        $customer = User::factory()->create();
        $group = $this->makeGroup('quick-draft-clear');
        $account = $this->makeB2BAccount($customer, B2BAccount::STATUS_APPROVED, $group);
        $product = $this->makeProduct('DRAFT-CLEAR-100', 42);
        $account->update([
            'quick_order_draft' => [[
                'product_id' => $product->id,
                'product_option_value_id' => null,
                'quantity' => 2,
            ]],
        ]);

        $this->actingAs($customer)
            ->putJson(route('account.b2b.quick-order.draft'), ['items' => []])
            ->assertOk()
            ->assertJson([
                'saved' => true,
                'count' => 0,
            ]);

        $this->assertNull($account->fresh()->quick_order_draft);
    }

    public function test_approved_customer_can_repeat_a_previous_order(): void
    {
        $customer = User::factory()->create();
        $group = $this->makeGroup('repeat-buyers');
        $this->makeB2BAccount($customer, B2BAccount::STATUS_APPROVED, $group);
        $product = $this->makeProduct('REPEAT-100', 40);
        $order = Order::query()->create([
            'order_number' => 'WEB-REPEAT-100',
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'code' => $product->code,
            'name' => 'Artikl za ponavljanje',
            'unit_price' => 40,
            'quantity' => 4,
            'line_total' => 160,
        ]);

        $response = $this->actingAs($customer)
            ->post(route('account.orders.reorder', ['orderNumber' => $order->order_number]));

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('front.cart.items.'.$product->id.':0.quantity', 4);
    }

    public function test_suspended_b2b_account_cannot_receive_group_prices_or_use_quick_order(): void
    {
        $customer = User::factory()->create();
        $group = $this->makeGroup('suspended-buyers');
        $this->makeB2BAccount($customer, B2BAccount::STATUS_SUSPENDED, $group);
        $product = $this->makeProduct('SUSPENDED-100', 100);
        B2BPriceRule::query()->create([
            'code' => 'SUSPENDED-GROUP-20',
            'name' => 'Grupni popust',
            'customer_group_id' => $group->id,
            'calculation_type' => B2BPriceRule::TYPE_PERCENTAGE_DISCOUNT,
            'value' => 20,
            'target_type' => B2BPriceRule::TARGET_ALL,
            'minimum_quantity' => 1,
            'currency_code' => 'EUR',
            'priority' => 10,
            'is_active' => true,
        ]);

        $resolved = app(ProductGroupPriceResolver::class)->resolve($product, $customer, 1);

        $this->assertNull($resolved);
        $this->actingAs($customer)
            ->get(route('account.b2b.quick-order'))
            ->assertForbidden();
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function makeGroup(string $code): CustomerGroup
    {
        return CustomerGroup::query()->create([
            'code' => $code,
            'name' => ucfirst(str_replace('-', ' ', $code)),
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 10,
        ]);
    }

    private function makeB2BAccount(
        User $user,
        string $status,
        ?CustomerGroup $group = null,
    ): B2BAccount {
        if ($group) {
            $user->customerGroups()->syncWithoutDetaching([$group->id]);
        }

        return B2BAccount::query()->create([
            'user_id' => $user->id,
            'status' => $status,
            'company_name' => 'Test tvrtka '.$user->id,
            'oib' => str_pad((string) $user->id, 11, '0', STR_PAD_LEFT),
            'phone' => '+385 1 555 000',
            'address_line_1' => 'Testna 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
            'customer_group_id' => $group?->id,
            'requested_at' => now(),
            'reviewed_at' => $status === B2BAccount::STATUS_APPROVED ? now() : null,
        ]);
    }

    private function makeProduct(
        string $code,
        float $price,
        ?string $sku = null,
        ?string $barcode = null,
    ): Product {
        $product = Product::query()->create([
            'code' => $code,
            'sku' => $sku ?: $code,
            'barcode' => $barcode,
            'is_active' => true,
            'base_price' => $price,
            'stock_qty' => 100,
            'minimum_order_quantity' => 1,
            'order_quantity_step' => 1,
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => 'Artikl '.$code,
            'slug' => strtolower($code),
        ]);

        return $product;
    }
}
