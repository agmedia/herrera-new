<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraProductShippingPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        ShippingMethod::query()->update(['is_active' => false]);
        $product = Product::query()->create(['code' => 'SHIPPING-PRODUCT', 'base_price' => 20, 'stock_qty' => 1, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Shipping product', 'slug' => 'shipping-product']);
        $this->legacyDestination('hr', 10, ['HR'], 5, 150, 150.01, 25);
        $this->legacyDestination('foreign', 20, ['SI', 'HU', 'IT'], 15, 200, 201, 0);
    }

    public function test_legacy_ranges_are_grouped_and_show_destination_thresholds_and_taxable_net_shipping(): void
    {
        $this->actingAs($this->buyer([9]));
        $html = $this->get(route('products.show', ['slug' => 'shipping-product']))->assertOk()
            ->assertSee('Odredišta: Hrvatska')
            ->assertSee('Slovenija')
            ->assertSee('Narudžbe do 150.00 €: 5.00 € bez PDV-a + PDV 25%')
            ->assertSee('Narudžbe od 150.01 €: Besplatno')
            ->assertSee('Narudžbe do 200.00 €: 15.00 € bez PDV-a')
            ->assertSee('Narudžbe od 201.00 €: Besplatno')
            ->assertSee('Pragovi se računaju prema vrijednosti artikala bez PDV-a prije popusta.')
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-product-shipping-method='));
        $this->assertSame(4, substr_count($html, 'data-product-shipping-range'));
        $this->assertSame(1, substr_count($html, '<h3>Dostava hr</h3>'));
        $this->assertSame(1, substr_count($html, '<h3>Dostava foreign</h3>'));
        $this->assertStringNotContainsString('10,000,000', $html);
    }

    public function test_contract_tax_exempt_buyers_do_not_see_shipping_vat_added(): void
    {
        $this->actingAs($this->buyer([]));
        $this->get(route('products.show', ['slug' => 'shipping-product']))->assertOk()
            ->assertSee('Narudžbe do 150.00 €: 5.00 € bez PDV-a')
            ->assertDontSee('5.00 € bez PDV-a + PDV 25%');
    }

    public function test_gross_display_includes_only_the_buyers_eligible_shipping_tax(): void
    {
        config(['commerce.b2b_display_net' => false]);
        $this->actingAs($this->buyer([9]));
        $this->get(route('products.show', ['slug' => 'shipping-product']))->assertOk()
            ->assertSee('Narudžbe do 150.00 €: 6.25 € s PDV-om')
            ->assertSee('Narudžbe do 200.00 €: 15.00 €');
    }

    public function test_guests_see_destinations_without_private_prices_or_free_order_thresholds(): void
    {
        $this->get(route('products.show', ['slug' => 'shipping-product']))->assertOk()
            ->assertSee('Odredišta: Hrvatska')
            ->assertDontSee('data-product-shipping-range', false)
            ->assertDontSee('Narudžbe od 150.01 €')
            ->assertDontSee('Narudžbe od 201.00 €');
    }

    public function test_generic_shipping_methods_keep_their_existing_price_and_quote_information(): void
    {
        ShippingMethod::query()->create(['code' => 'generic-flat', 'name' => 'Generic paid', 'price' => 8, 'free_over' => 500, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'generic-quote', 'name' => 'Generic quote', 'pricing_type' => 'quote', 'is_active' => true]);
        $this->actingAs($this->buyer([9]));
        $html = $this->get(route('products.show', ['slug' => 'shipping-product']))->assertOk()
            ->assertSee('Generic paid')->assertSee('Generic quote')
            ->assertSee('Cijena dostave: 8.00 €')
            ->assertSee('Besplatno za narudžbe iznad 500.00 €')
            ->assertSee('Cijena dostave potvrđuje se nakon slanja upita.')
            ->getContent();

        $this->assertSame(4, substr_count($html, 'data-product-shipping-method='));
    }

    private function buyer(array $taxableClasses): User
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create([
            'code' => 'shipping-buyer', 'name' => 'Shipping buyer', 'is_active' => true,
            'payload' => ['opencart' => ['tax_scope_defined' => true, 'taxable_class_ids' => $taxableClasses]],
        ]);
        B2BAccount::query()->create([
            'user_id' => $user->id, 'company_name' => 'Shipping buyer', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
        ]);

        return $user;
    }

    private function legacyDestination(string $code, int $sourceId, array $countries, float $price, float $paidEnd, float $freeStart, float $tax): void
    {
        $zone = GeoZone::query()->create(['code' => $code, 'name' => $code, 'is_active' => true]);
        foreach ($countries as $country) {
            GeoZoneCountry::query()->create(['geo_zone_id' => $zone->id, 'country_code' => $country]);
        }
        foreach ([[$price, 0, $paidEnd], [0, $freeStart, 10000000]] as $index => [$cost, $minimum, $maximum]) {
            ShippingMethod::query()->create([
                'code' => $code.'-'.$index, 'name' => 'Dostava '.$code, 'pricing_type' => $cost > 0 ? 'flat' : 'free',
                'geo_zone_id' => $zone->id, 'price' => $cost, 'min_subtotal' => $minimum, 'max_subtotal' => $maximum,
                'is_active' => true, 'sort_order' => $sourceId + $index,
                'settings' => ['configured_from' => 'herrera-opencart', 'source_method_id' => $sourceId,
                    'subtotal_basis' => 'before_discount', 'tax_class_id' => 9, 'tax_rate_percent' => $tax],
            ]);
        }
    }
}
