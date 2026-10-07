<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\ProductGroupPriceResolver;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class B2BAccessFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
    }

    #[DataProvider('deniedStates')]
    public function test_an_ineligible_account_never_receives_a_price_or_can_order(string $state): void
    {
        [$user, $group, $account] = $this->approvedCustomer();
        $product = $this->product('ACCESS', 137.49);

        match ($state) {
            'guest' => $user = null,
            'no_account' => $account->delete(),
            'pending', 'rejected', 'suspended' => $account->update(['status' => $state]),
            'expired' => $account->update(['contract_ends_at' => now()->subDay()]),
            'future' => $account->update(['contract_starts_at' => now()->addDay()]),
            'inactive_group' => $group->update(['is_active' => false]),
            'no_group' => $account->update(['customer_group_id' => null]),
        };

        $this->assertFalse(app(B2BAccessService::class)->canViewPrices($user));
        $this->assertNull(app(ProductGroupPriceResolver::class)->resolve($product, $user));
        $price = app(ProductPricePresentationService::class)->forProduct($product, $user);
        $this->assertFalse($price['can_view_price']);
        foreach (['current_gross', 'current_net', 'base_gross', 'catalog_gross', 'old_gross', 'discount_percent', 'lowest_30_days_gross', 'price_source', 'group_price_id', 'b2b_rule_id'] as $key) {
            $this->assertNull($price[$key], $key);
        }

        if ($user) {
            $this->actingAs($user);
        }
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertForbidden()->assertJsonMissingPath('summary');
        $this->postJson(route('checkout.store'), [])
            ->assertForbidden()->assertJsonMissingPath('total');
        $this->assertDatabaseCount('orders', 0);
    }

    public static function deniedStates(): array
    {
        return array_map(static fn (string $state): array => [$state], [
            'guest', 'no_account', 'pending', 'rejected', 'suspended', 'expired', 'future', 'inactive_group', 'no_group',
        ]);
    }

    public function test_promotions_menu_requires_an_approved_b2b_account(): void
    {
        $this->get(route('shop.promotions'))->assertRedirect(route('front.auth.login'))
            ->assertSessionHas('url.intended', route('shop.promotions'));
        [$user, , $account] = $this->approvedCustomer();
        $this->actingAs($user)->get(route('shop.promotions'))
            ->assertRedirect(route('shop.index', ['promo_only' => 1]));
        $account->update(['status' => 'pending']);
        $user->unsetRelation('b2bAccount');
        $this->get(route('shop.promotions'))->assertRedirect(route('account.dashboard'));
    }

    public function test_only_the_approved_accounts_assigned_group_sets_its_price(): void
    {
        [$user, $group] = $this->approvedCustomer();
        $other = CustomerGroup::query()->create(['code' => 'extra', 'name' => 'Extra group', 'is_active' => true]);
        $user->customerGroups()->attach($other);
        $product = $this->product('ASSIGNED', 137.49);
        foreach ([[$group, 91.23], [$other, 12.34]] as [$audience, $amount]) {
            ProductGroupPrice::query()->create([
                'product_id' => $product->id,
                'customer_group_id' => $audience->id,
                'price' => $amount,
                'minimum_quantity' => 1,
                'currency_code' => 'EUR',
                'is_active' => true,
            ]);
        }

        $this->assertTrue(app(B2BAccessService::class)->canViewPrices($user));
        $this->assertSame(91.23, app(ProductGroupPriceResolver::class)->storedPrice($product, $user));
        $this->actingAs($user)->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk()->assertJsonPath('summary.line_count', 1);
        $this->assertSame(91.23, (float) app(CartService::class)->lines()->first()['unit_price']);
    }

    public function test_promotions_from_an_extra_group_cannot_change_the_assigned_group_price(): void
    {
        [$user, $group] = $this->approvedCustomer();
        $other = CustomerGroup::query()->create(['code' => 'extra-promotion', 'name' => 'Extra promotion', 'is_active' => true]);
        $user->customerGroups()->attach($other);
        $product = $this->product('PROMOTION-GROUP', 100);
        ProductGroupPrice::query()->create([
            'product_id' => $product->id, 'customer_group_id' => $group->id,
            'price' => 80, 'minimum_quantity' => 1, 'currency_code' => 'EUR', 'is_active' => true,
        ]);
        CatalogAction::query()->create([
            'code' => 'OTHER-GROUP-50', 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 50,
            'target_type' => CatalogAction::TARGET_ALL, 'audience_type' => CatalogAction::AUDIENCE_USER_GROUP,
            'customer_group_id' => $other->id, 'is_active' => true,
        ]);

        $this->assertSame(80.0, app(ProductPricePresentationService::class)->forProduct($product, $user)['current_gross']);
        $this->actingAs($user);
        app(CartService::class)->add($product);
        $this->assertSame(80.0, (float) app(CartService::class)->lines()->first()['unit_price']);
    }

    public function test_an_approved_customer_can_complete_checkout_at_their_assigned_price(): void
    {
        [$user, $group] = $this->approvedCustomer();
        $product = $this->product('APPROVED-CHECKOUT', 137.49);
        ProductGroupPrice::query()->create([
            'product_id' => $product->id, 'customer_group_id' => $group->id,
            'price' => 91.23, 'minimum_quantity' => 1, 'currency_code' => 'EUR', 'is_active' => true,
        ]);
        Currency::query()->create([
            'code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => 1,
            'decimal_places' => 2, 'is_default' => true, 'is_active' => true,
        ]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 4.99, 'is_active' => true]);
        PaymentMethod::query()->create([
            'code' => 'bank', 'name' => 'Bank transfer', 'provider' => 'bank',
            'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(route('cart.items.store'), [
            'product_id' => $product->id, 'quantity' => 2,
        ])->assertOk();
        $response = $this->post(route('checkout.store'), [
            'customer_first_name' => 'Test', 'customer_last_name' => 'Customer',
            'customer_email' => $user->email, 'customer_phone' => '+38591000002',
            'billing_first_name' => 'Test', 'billing_last_name' => 'Customer',
            'billing_company' => 'Test business', 'billing_oib' => '12345678901',
            'billing_address_line_1' => 'Main Street 1', 'billing_postal_code' => '10000',
            'billing_city' => 'Zagreb', 'billing_country_code' => 'HR',
            'use_billing_for_shipping' => '1',
            'shipping_method_code' => 'standard', 'payment_method_code' => 'bank', 'accept_terms' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $response->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]));
        $this->assertSame($user->id, $order->user_id);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => 2, 'unit_price' => 91.23, 'line_total' => 182.46,
        ]);
        $this->assertSame(8, (int) $product->fresh()->stock_qty);
        $this->assertTrue(app(CartService::class)->lines()->isEmpty());
    }

    public function test_all_registration_entry_points_require_the_business_registration_flow(): void
    {
        $this->get(route('front.auth.login'))->assertOk()
            ->assertSee(route('front.auth.b2b-register'), false);
        $this->get(route('front.auth.register'))->assertRedirect(route('front.auth.b2b-register'));
        $this->get('/register')->assertRedirect(route('front.auth.b2b-register'));
        $this->post(route('front.auth.register.store'), [
            'first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'test@business.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ])->assertSessionHasErrors(['company_name', 'oib']);
        $this->assertDatabaseMissing('users', ['email' => 'test@business.test']);

        // Also deny a component that was already mounted before B2B-only mode was enabled.
        config(['commerce.b2b_only' => false]);
        $registration = Volt::test('pages.auth.register');
        config(['commerce.b2b_only' => true]);
        $registration->call('register')->assertRedirect(route('front.auth.b2b-register'));
        $this->assertGuest();
    }

    #[DataProvider('legacyTaxScopes')]
    public function test_legacy_tax_applies_only_to_the_assigned_contract_group_and_product_class(bool $taxableGroup, int $productClass, float $expectedTax): void
    {
        [$user, $group] = $this->approvedCustomer();
        $group->update(['payload' => ['opencart' => [
            'tax_scope_defined' => true, 'taxable_class_ids' => $taxableGroup ? [11] : [],
        ]]]);
        $tax = TaxRate::query()->create(['code' => 'PDV25', 'name' => 'PDV 25%', 'rate' => 25, 'rate_type' => 'percent', 'is_active' => true, 'is_default' => true]);
        $product = $this->product('CONTRACT-TAX', 91.23);
        $product->update(['tax_rate_id' => $tax->id, 'payload' => ['opencart' => ['tax_class_id' => $productClass]]]);

        // The explicit buyer must determine tax even during an admin simulation.
        $price = app(ProductPricePresentationService::class)->forProduct($product, $user);
        $this->assertSame(91.23, $price['current_net']);
        $this->assertSame(91.23, $price['display_current']);
        $this->assertFalse($price['display_includes_tax']);
        $this->assertSame($expectedTax > 0 ? 114.04 : 91.23, $price['current_gross']);

        $this->actingAs($user);
        app(CartService::class)->add($product, 2);
        $this->assertSame(182.46, app(CartService::class)->summary()['subtotal']);
        $this->assertSame($expectedTax, app(CartService::class)->summary()['tax_total']);
    }

    public static function legacyTaxScopes(): array
    {
        return [[true, 11, 45.62], [false, 11, 0.0], [true, 0, 0.0], [false, 0, 0.0]];
    }

    public function test_a_preexisting_guest_cart_cannot_reveal_totals_or_be_used_for_checkout(): void
    {
        $product = $this->product('OLD-CART', 137.49);
        session()->put('front.cart.items', [
            $product->id.':0' => ['product_id' => $product->id, 'product_option_value_id' => null, 'quantity' => 2],
        ]);

        $this->assertTrue(app(CartService::class)->lines()->isEmpty());
        $this->assertSame(0.0, app(CartService::class)->summary()['grand_total']);
        $this->get(route('cart.preview'))->assertRedirect(route('front.auth.login'));
        $this->getJson(route('cart.preview'))->assertForbidden()->assertJsonMissingPath('summary');
        $this->getJson(route('checkout.options'))->assertForbidden();

        try {
            app(CheckoutService::class)->placeOrder([]);
            $this->fail('A guest must not reach order creation.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(10, (int) $product->fresh()->stock_qty);
        }
    }

    public function test_revoking_access_blocks_direct_cart_and_checkout_service_calls(): void
    {
        [$user, , $account] = $this->approvedCustomer();
        $product = $this->product('REVOKED', 137.49);
        $this->actingAs($user);
        app(CartService::class)->add($product);

        $account->update(['status' => B2BAccount::STATUS_SUSPENDED]);
        $user->unsetRelation('b2bAccount');
        $this->assertTrue(app(CartService::class)->lines()->isEmpty());

        foreach ([
            fn () => app(CartService::class)->add($product),
            fn () => app(CartService::class)->set($product, 2),
            fn () => app(CartService::class)->replaceRaw([]),
            fn () => app(CartService::class)->applyCoupon('TEST'),
            fn () => app(CheckoutService::class)->placeOrder([], $user),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Suspended access must be denied by the service.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('orders', 0);
            }
        }
    }

    public function test_guest_search_returns_catalog_metadata_without_prices(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_search_autocomplete_enabled' => true,
            'store_search_autocomplete_show_product_price' => true,
            'store_search_autocomplete_products_enabled' => true,
        ]);
        $product = $this->product('SEARCH', 137.49);

        $response = $this->getJson(route('search.autocomplete', ['q' => 'Private']));
        $response->assertOk()->assertJsonPath('items.0.id', $product->id)
            ->assertJsonPath('items.0.price', null)
            ->assertJsonPath('items.0.old_price', null)
            ->assertJsonPath('items.0.has_discount', false);
        $this->assertStringNotContainsString('137.49', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_guest_price_and_availability_filters_do_not_form_a_price_oracle(): void
    {
        $category = Category::query()->create(['code' => 'PRIVATE-CATALOG', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => 'Private catalog', 'slug' => 'private-catalog']);
        foreach ([['CHEAP', 12.34, 10], ['EXPENSIVE', 137.49, 0]] as [$code, $amount, $stock]) {
            $product = $this->product($code, $amount);
            $product->update(['stock_qty' => $stock]);
            $product->categories()->attach($category);
        }
        app(SystemSettingsService::class)->put('catalog_hide_out_of_stock_products', false);

        foreach ([route('shop.index'), route('categories.show', ['slug' => 'private-catalog'])] as $url) {
            $this->get($url.'?price_min=100&price_max=150&sort=price_high&available_only=1&promo_only=1')
                ->assertOk()
                ->assertViewHas('products', fn ($products) => $products->total() === 2)
                ->assertViewHas('priceBounds', ['min' => null, 'max' => null])
                ->assertViewHas('filters', fn (array $filters) => $filters['price_min'] === null
                    && $filters['price_max'] === null && ! $filters['available_only'] && ! $filters['promo_only']);
        }
    }

    private function approvedCustomer(): array
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'approved', 'name' => 'Approved B2B', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        $account = B2BAccount::query()->create([
            'user_id' => $user->id,
            'company_name' => 'Test business',
            'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED,
            'customer_group_id' => $group->id,
        ]);

        return [$user, $group, $account];
    }

    private function product(string $code, float $amount): Product
    {
        $product = Product::query()->create(['code' => $code, 'sku' => $code, 'base_price' => $amount, 'stock_qty' => 10, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'slug' => strtolower($code), 'name' => 'Private '.$code]);

        return $product;
    }
}
