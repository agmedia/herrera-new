<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Feeds\CustomerCatalogFeedAccess;
use App\Services\Feeds\CustomerWholesaleFeedService;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Pricing\PriceCatalogQuery;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Pricing\TaxPricingService;
use App\Services\Sales\CustomerOfferService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ForeignBusinessTaxExemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        Mail::fake();
        Http::preventStrayRequests();
    }

    public static function priceModes(): array
    {
        return [
            'net and net display' => [false, true, true, true, 'percent', 25],
            'net and gross display' => [false, false, true, true, 'percent', 25],
            'gross and net display' => [true, true, true, true, 'percent', 25],
            'gross and gross display' => [true, false, true, true, 'percent', 25],
            'mixed retail net' => [false, false, false, false, 'percent', 25],
            'mixed retail gross' => [true, false, false, false, 'percent', 25],
            'no wholesale catalogue' => [true, true, true, false, 'percent', 25],
            'fixed source tax' => [true, false, true, true, 'fixed', 5],
        ];
    }

    #[DataProvider('priceModes')]
    public function test_foreign_company_prices_match_sql_catalogue_cart_and_offer_without_leaving_stored_vat(bool $gross, bool $netDisplay, bool $b2bOnly, bool $published, string $type, int $rate): void
    {
        config(['commerce.b2b_only' => $b2bOnly, 'commerce.b2b_display_net' => $netDisplay]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', $gross);
        [$buyer, $group] = $this->buyer('SI');
        $tax = $this->tax($type, $rate);
        $stored = $gross ? ($type === 'fixed' ? 100 + $rate : 100 * (1 + $rate / 100)) : 100;
        $first = $this->product('FIRST', $stored, $tax);
        $second = $this->product('SECOND', 110, $this->tax('percent', 0, 'ZERO'));
        if ($published) {
            $catalog = PriceCatalog::create(['name' => 'Foreign company test', 'status' => 'draft', 'currency_code' => 'EUR']);
            foreach ([$first, $second] as $product) {
                $catalog->entries()->create(['source_key' => 'foreign-'.$product->id, 'product_id' => $product->id,
                    'kind' => 'group', 'price' => $product->base_price, 'customer_group_id' => $group->id,
                    'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
            }
            app(PriceCatalogService::class)->activate($catalog, $this->admin());
        }
        $this->actingAs($buyer);
        $pricing = app(TaxPricingService::class);
        $this->assertTrue($pricing->isForeignBusinessExempt($buyer));
        $this->assertSame(100.0, $pricing->normalizeNetPrice($stored, $first, user: $buyer));
        $this->assertSame(100.0, $pricing->grossFromStored($stored, $first, user: $buyer));
        $this->assertSame(0.0, $pricing->taxFromStored($stored, $first, user: $buyer));
        // Already-presented exempt gross is net too; never strip catalogue VAT twice.
        $this->assertSame(100.0, $pricing->netFromGross(100, $first, user: $buyer));
        $this->assertSame(100.0, app(ProductPricePresentationService::class)->forProduct($first, $buyer)['display_current']);
        $query = Product::query()->select('products.id');
        $expression = app(PriceCatalogQuery::class)->displayedPrice($query, $buyer);
        $this->assertNotNull($expression);
        $rows = $query->selectRaw($expression['sql'].' as effective_price', $expression['bindings'])->get();
        $this->assertSame(100.0, (float) $rows->firstWhere('id', $first->id)->effective_price);
        $this->get(route('shop.index', ['sort' => 'price_low']))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->pluck('id')->all() === [$first->id, $second->id])
            ->assertViewHas('priceBounds', ['min' => 100.0, 'max' => 110.0]);
        $this->get(route('shop.index', ['price_max' => 105]))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->pluck('id')->all() === [$first->id]);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($first, 2));
        $line = $cart->lines()->sole();
        $this->assertSame(100.0, $line['unit_price']);
        $this->assertSame(0.0, $line['line_tax_total']);
        $this->assertSame(200.0, $cart->summary()['grand_total']);
        $offer = app(CustomerOfferService::class)->saveDraft([
            'customer_user_id' => $buyer->id, 'title' => 'Foreign company test', 'locale' => 'hr',
            'valid_until' => now()->addDays(7)->format('Y-m-d'),
            'items' => [['product_id' => $first->id, 'quantity' => 2]],
        ], $this->admin());
        $this->assertSame(100.0, (float) $offer->items()->sole()->unit_net);
        $this->assertSame(100.0, (float) $offer->items()->sole()->unit_gross);
        $this->assertSame(0.0, (float) $offer->tax_total);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public static function deniedIdentities(): array
    {
        return array_map(fn ($state) => [$state], ['guest', 'private', 'HR', 'missing_country', 'invalid_country',
            'missing_company', 'pending', 'rejected', 'suspended', 'expired', 'future', 'inactive_group', 'no_group', 'staff']);
    }

    #[DataProvider('deniedIdentities')]
    public function test_untrusted_or_incomplete_identity_never_grants_the_foreign_exemption(string $state): void
    {
        [$buyer, $group, $account] = $this->buyer('SI');
        $tax = $this->tax();
        $product = $this->product('GUARDED', 100, $tax);
        match ($state) {
            'guest' => $buyer = null,
            'private' => $account->delete(),
            'HR' => $account->update(['country_code' => 'HR']),
            'missing_country' => $account->update(['country_code' => '']),
            'invalid_country' => $account->update(['country_code' => 'XX']),
            'missing_company' => $account->update(['company_name' => '  ']),
            'pending', 'rejected', 'suspended' => $account->update(['status' => $state]),
            'expired' => $account->update(['contract_ends_at' => now()->subDay()]),
            'future' => $account->update(['contract_starts_at' => now()->addDay()]),
            'inactive_group' => $group->update(['is_active' => false]),
            'no_group' => $account->update(['customer_group_id' => null]),
            'staff' => $buyer->forceFill(['account_type' => 'staff'])->save(),
        };
        $buyer?->unsetRelation('b2bAccount');
        $pricing = app(TaxPricingService::class);
        $this->assertFalse($pricing->isForeignBusinessExempt($buyer));
        $this->assertSame(25.0, (float) $pricing->resolveRateForProduct($product, $buyer)->rate);
        // Public retail remains taxable even when an unapproved account entered a company abroad.
        config(['commerce.b2b_only' => false]);
        $this->assertFalse($pricing->isForeignBusinessExempt($buyer));
        $this->assertSame(125.0, $pricing->grossFromStored(100, $product, user: $buyer));
    }

    public static function checkoutCountries(): array
    {
        return [['SI', 'HR', 0.0, 210.0], ['HR', 'SI', 52.5, 262.5]];
    }

    #[DataProvider('checkoutCountries')]
    public function test_order_and_shipping_tax_use_approved_company_country_not_submitted_checkout_addresses(string $approvedCountry, string $checkoutCountry, float $expectedTax, float $expectedTotal): void
    {
        [$buyer] = $this->buyer($approvedCountry);
        $product = $this->product('ORDER', 100, $this->tax());
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::create(['code' => 'standard', 'name' => 'Standard', 'price' => 10, 'is_active' => true,
            'settings' => ['tax_rate_percent' => 25, 'tax_class_id' => 11]]);
        PaymentMethod::create(['code' => 'bank', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
        $this->actingAs($buyer);
        app(CartService::class)->add($product, 2);
        $order = app(CheckoutService::class)->placeOrder([
            'customer_first_name' => 'Test', 'customer_last_name' => 'Buyer', 'customer_email' => $buyer->email,
            'billing_company' => 'Untrusted submitted company', 'billing_country_code' => $checkoutCountry,
            'shipping_country_code' => $checkoutCountry, 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank',
        ], $buyer);
        $this->assertSame($expectedTax, (float) $order->tax_total);
        $this->assertSame($expectedTotal, (float) $order->grand_total);
        $this->assertSame($expectedTax === 0.0 ? 0.0 : 50.0, (float) $order->items()->sole()->tax_amount);
        $this->assertSame($approvedCountry, $buyer->b2bAccount->fresh()->country_code);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_domestic_contract_zero_tax_and_explicit_source_rate_are_preserved(): void
    {
        [$domestic, $group] = $this->buyer('HR');
        $group->update(['payload' => ['opencart' => ['tax_scope_defined' => true, 'taxable_class_ids' => []]]]);
        $domestic->unsetRelation('b2bAccount');
        $product = $this->product('CONTRACT', 100, $tax = $this->tax());
        $pricing = app(TaxPricingService::class);
        $this->assertFalse($pricing->isForeignBusinessExempt($domestic));
        $this->assertSame(0.0, (float) $pricing->resolveRateForProduct($product, $domestic)->rate);
        [$foreign] = $this->buyer('si');
        $this->actingAs($foreign);
        $this->assertTrue($pricing->isForeignBusinessExempt($foreign));
        $this->assertSame(125.0, $pricing->grossFromNet(100, $product, taxRate: $tax));
        $this->assertSame(100.0, $pricing->netFromGross(125, $product, taxRate: $tax));
    }

    public function test_customer_and_public_xml_source_prices_ignore_the_signed_in_buyers_tax_exemption(): void
    {
        [$buyer] = $this->buyer('SI');
        $this->tax();
        $product = $this->product('XML', 125, $this->tax('percent', 0, 'UNUSED'));
        $product->update(['tax_rate_id' => null]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        $feed = app(CustomerCatalogFeedAccess::class)->saveAssortment($buyer, $this->admin(), [$product->id], false);
        $this->actingAs($buyer);
        ob_start();
        app(CustomerWholesaleFeedService::class)->stream($feed);
        $xml = simplexml_load_string(ob_get_clean());
        $this->assertSame('100.0000', (string) $xml->products->product->price);

        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $product->update(['base_price' => 100]);
        config(['commerce.b2b_only' => false, 'services.nabava_net.enabled' => true, 'services.nabava_net.username' => 'synthetic',
            'services.nabava_net.password' => 'synthetic', 'services.nabava_net.locale' => 'hr']);
        $response = $this->get('/feeds/nabava.xml?username=synthetic&password=synthetic')->assertOk();
        $publicXml = simplexml_load_string($response->streamedContent());
        $this->assertSame('125,00 €', (string) $publicXml->artikl[0]->cijena);
        Http::assertNothingSent();
    }

    private function buyer(string $country): array
    {
        $buyer = User::factory()->create();
        $group = CustomerGroup::create(['code' => 'foreign-'.$buyer->id, 'name' => 'Taxable legacy group', 'is_active' => true,
            'payload' => ['opencart' => ['tax_scope_defined' => true, 'taxable_class_ids' => [11]]]]);
        $buyer->customerGroups()->attach($group);
        $account = B2BAccount::create(['user_id' => $buyer->id, 'status' => 'approved', 'company_name' => 'Approved business',
            'country_code' => $country, 'oib' => str_pad((string) $buyer->id, 11, '0', STR_PAD_LEFT), 'customer_group_id' => $group->id]);

        return [$buyer, $group, $account];
    }

    private function tax(string $type = 'percent', int $rate = 25, string $code = 'VAT25'): TaxRate
    {
        return TaxRate::create(['code' => $code, 'name' => $code, 'rate_type' => $type, 'rate' => $rate,
            'is_active' => true, 'is_default' => $code === 'VAT25']);
    }

    private function product(string $code, float $price, TaxRate $tax): Product
    {
        $product = Product::create(['code' => $code, 'sku' => $code, 'base_price' => $price, 'stock_qty' => 100,
            'tax_rate_id' => $tax->id, 'is_active' => true, 'payload' => ['opencart' => ['tax_class_id' => 11]]]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Product '.$code, 'slug' => strtolower($code)]);

        return $product;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['account_type' => 'staff'])->save();
        Bouncer::assign('superadmin')->to($admin);

        return $admin;
    }
}
