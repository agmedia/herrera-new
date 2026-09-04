<?php

namespace Tests\Feature\Shipping;

use App\Livewire\Admin\Settings\Local\ResourceManager;
use App\Livewire\Admin\Shipping\ShippingManager;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\ShippingMethodRate;
use App\Models\Settings\System\SystemSetting;
use App\Models\User;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Integrations\Gls\GlsApiService;
use App\Services\Integrations\Gls\GlsShipmentService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\CroatianIslandDestinationClassifier;
use App\Services\Shipping\ShippingCalculator;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class AdvancedShippingFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_parcel_locker_limits_and_product_or_category_labels_are_enforced(): void
    {
        $calculator = app(ShippingCalculator::class);
        $method = $this->shippingMethod([
            'code' => 'boxnow',
            'carrier' => 'boxnow',
            'service_type' => 'parcel_locker',
            'max_weight_kg' => 20,
            'max_length_cm' => 60,
            'max_width_cm' => 45,
            'max_height_cm' => 36,
            'allows_fragile' => false,
            'allows_oversized' => false,
            'allows_heavy' => false,
            'missing_measurements_policy' => 'block',
        ]);

        $product = $this->product([
            'weight_kg' => 2,
            'length_cm' => 36,
            'width_cm' => 60,
            'height_cm' => 45,
        ]);

        $quote = $calculator->quote($method, $this->lines($product), 50);

        $this->assertNotNull($quote);
        $this->assertSame(4.99, $quote['price']);

        $product->shipping_labels = ['fragile'];
        $this->assertNull($calculator->quote($method, $this->lines($product), 50));

        $product->shipping_labels = [];
        $product->weight_kg = 20.001;
        $this->assertNull($calculator->quote($method, $this->lines($product), 50));

        $product->weight_kg = 2;
        $product->setRelation('categories', collect([
            new Category(['payload' => ['shipping_labels' => ['no_parcel_locker']]]),
        ]));
        $this->assertNull($calculator->quote($method, $this->lines($product), 50));
    }

    public function test_weight_tiers_surcharges_and_free_shipping_are_combined(): void
    {
        $calculator = app(ShippingCalculator::class);
        $method = $this->shippingMethod([
            'code' => 'weighted',
            'pricing_type' => 'weight_tiers',
            'fragile_surcharge' => 2,
        ]);
        $method->setRelation('rates', collect([
            new ShippingMethodRate([
                'min_weight_kg' => 0,
                'max_weight_kg' => 2,
                'price' => 4,
            ]),
            new ShippingMethodRate([
                'min_weight_kg' => 2.001,
                'max_weight_kg' => 5,
                'price' => 7,
            ]),
        ]));

        $product = $this->product([
            'weight_kg' => 2.5,
            'shipping_labels' => ['fragile'],
        ]);

        $quote = $calculator->quote($method, $this->lines($product), 40);

        $this->assertNotNull($quote);
        $this->assertSame(7.0, $quote['base_price']);
        $this->assertSame(2.0, $quote['surcharges']['fragile']);
        $this->assertSame(9.0, $quote['price']);

        $product->shipping_labels = ['free_shipping'];
        $freeQuote = $calculator->quote($method, $this->lines($product), 40);

        $this->assertNotNull($freeQuote);
        $this->assertSame('free', $freeQuote['pricing_type']);
        $this->assertSame(0.0, $freeQuote['price']);
    }

    public function test_admin_shipping_module_saves_a_weight_tariff(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(ShippingManager::class)
            ->set('form.code', 'test_weight')
            ->set('form.name', 'Testna dostava po težini')
            ->set('form.carrier', 'manual')
            ->set('form.service_type', 'home_delivery')
            ->set('form.pricing_type', 'weight_tiers')
            ->set('form.max_weight_kg', 20)
            ->set('rates', [
                [
                    'id' => null,
                    'min_weight_kg' => 0,
                    'max_weight_kg' => 5,
                    'price' => 4.5,
                    'sort_order' => 0,
                ],
                [
                    'id' => null,
                    'min_weight_kg' => 5.001,
                    'max_weight_kg' => 20,
                    'price' => 8.5,
                    'sort_order' => 1,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $this->assertDatabaseHas('shipping_methods', [
            'code' => 'test_weight',
            'pricing_type' => 'weight_tiers',
            'max_weight_kg' => 20,
        ]);
        $savedMethod = ShippingMethod::query()->where('code', 'test_weight')->firstOrFail();
        $this->assertSame(2, $savedMethod->rates()->count());
    }

    public function test_admin_cannot_activate_boxnow_until_all_safety_settings_are_ready(): void
    {
        $admin = User::factory()->create();
        $boxNow = ShippingMethod::query()->updateOrCreate(
            ['code' => 'boxnow'],
            [
                'name' => 'BOX NOW paketomat',
                'carrier' => 'boxnow',
                'service_type' => 'parcel_locker',
                'pricing_type' => 'flat',
                'max_weight_kg' => 20,
                'max_length_cm' => 60,
                'max_width_cm' => 45,
                'max_height_cm' => 36,
                'allows_fragile' => false,
                'allows_oversized' => false,
                'allows_heavy' => false,
                'missing_measurements_policy' => 'block',
                'is_active' => false,
                'settings' => ['boxnow_partner_id' => ''],
            ],
        );

        Livewire::actingAs($admin)
            ->test(ShippingManager::class)
            ->call('toggleActive', $boxNow->id)
            ->assertHasErrors(['form.boxnow_partner_id']);
        $this->assertFalse($boxNow->refresh()->is_active);

        $boxNow->update(['settings' => ['boxnow_partner_id' => 'partner-test']]);
        Livewire::actingAs($admin)
            ->test(ShippingManager::class)
            ->call('toggleActive', $boxNow->id)
            ->assertHasNoErrors();
        $this->assertTrue($boxNow->refresh()->is_active);

        Livewire::actingAs($admin)
            ->test(ShippingManager::class)
            ->call('edit', $boxNow->id)
            ->set('form.service_type', 'home_delivery')
            ->set('form.allows_fragile', true)
            ->call('save')
            ->assertHasErrors(['form.service_type', 'form.allows_fragile']);
        $this->assertSame('parcel_locker', $boxNow->refresh()->service_type);
        $this->assertFalse($boxNow->allows_fragile);

        $boxNow->update([
            'is_active' => false,
            'settings' => ['boxnow_partner_id' => ''],
        ]);
        Livewire::actingAs($admin)
            ->test(ResourceManager::class, ['resource' => 'shipping-methods'])
            ->call('toggleActive', $boxNow->id)
            ->assertHasErrors(['form.boxnow_partner_id']);
        $this->assertFalse($boxNow->refresh()->is_active);

        $legacyBoxNow = ShippingMethod::query()->create([
            'code' => 'box_now',
            'name' => 'Legacy BOX NOW',
            'carrier' => 'manual',
            'service_type' => 'parcel_locker',
            'pricing_type' => 'flat',
            'max_weight_kg' => 20,
            'max_length_cm' => 60,
            'max_width_cm' => 45,
            'max_height_cm' => 36,
            'missing_measurements_policy' => 'block',
            'is_active' => false,
            'settings' => ['boxnow_partner_id' => ''],
        ]);

        Livewire::actingAs($admin)
            ->test(ShippingManager::class)
            ->call('toggleActive', $legacyBoxNow->id)
            ->assertHasErrors(['form.boxnow_partner_id']);
        $this->assertFalse($legacyBoxNow->refresh()->is_active);
    }

    public function test_admin_boxnow_connection_fields_render_load_and_save_without_exposing_the_secret(): void
    {
        $admin = User::factory()->create();
        $storedPlaintext = 'previous-client-secret';
        $storedCiphertext = Crypt::encryptString($storedPlaintext);
        $boxNow = $this->persistedBoxNow([
            'boxnow_partner_id' => 'partner-before',
            'boxnow_api_url' => 'https://api-before.boxnow.example',
            'boxnow_oauth_client_id' => 'client-before',
            'boxnow_oauth_client_secret_encrypted' => $storedCiphertext,
            'boxnow_warehouse_id' => 'warehouse-before',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ShippingManager::class, ['editPage' => true, 'recordId' => $boxNow->id])
            ->assertSeeHtml('wire:model="form.boxnow_partner_id"')
            ->assertSeeHtml('wire:model="form.boxnow_api_url"')
            ->assertSeeHtml('wire:model="form.boxnow_oauth_client_id"')
            ->assertSeeHtml('wire:model="form.boxnow_oauth_client_secret"')
            ->assertSeeHtml('type="password"')
            ->assertSeeHtml('autocomplete="new-password"')
            ->assertSeeHtml('wire:model="form.boxnow_warehouse_id"')
            ->assertSet('form.boxnow_partner_id', 'partner-before')
            ->assertSet('form.boxnow_api_url', 'https://api-before.boxnow.example')
            ->assertSet('form.boxnow_oauth_client_id', 'client-before')
            ->assertSet('form.boxnow_oauth_client_secret', '')
            ->assertSet('form.boxnow_warehouse_id', 'warehouse-before')
            ->assertSet('boxNowOauthClientSecretStored', true)
            ->assertSee('Spremljeno')
            ->assertDontSee($storedPlaintext)
            ->assertDontSee($storedCiphertext)
            ->assertDontSee('boxnow_oauth_client_secret_encrypted');

        $component
            ->set('form.boxnow_partner_id', 'partner-after')
            ->set('form.boxnow_api_url', 'https://api.boxnow.example/v1')
            ->set('form.boxnow_oauth_client_id', 'client-after')
            ->set('form.boxnow_oauth_client_secret', 'replacement-client-secret')
            ->set('form.boxnow_warehouse_id', 'warehouse-after')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.shipping.index', ['tab' => 'methods']));

        $settings = $boxNow->refresh()->settings;
        $this->assertSame('partner-after', $settings['boxnow_partner_id']);
        $this->assertSame('https://api.boxnow.example/v1', $settings['boxnow_api_url']);
        $this->assertSame('client-after', $settings['boxnow_oauth_client_id']);
        $this->assertSame('warehouse-after', $settings['boxnow_warehouse_id']);
        $this->assertNotSame('replacement-client-secret', $settings['boxnow_oauth_client_secret_encrypted']);
        $this->assertStringNotContainsString('replacement-client-secret', $settings['boxnow_oauth_client_secret_encrypted']);
        $this->assertArrayNotHasKey('boxnow_oauth_client_secret', $settings);
        $this->assertStringNotContainsString('replacement-client-secret', (string) $boxNow->getRawOriginal('settings'));
        $this->assertSame(
            'replacement-client-secret',
            Crypt::decryptString($settings['boxnow_oauth_client_secret_encrypted']),
        );

        Livewire::actingAs($admin)
            ->test(ShippingManager::class, ['editPage' => true, 'recordId' => $boxNow->id])
            ->assertSet('form.boxnow_oauth_client_secret', '')
            ->assertSet('boxNowOauthClientSecretStored', true)
            ->assertDontSee('replacement-client-secret');
    }

    public function test_admin_boxnow_blank_client_secret_preserves_the_encrypted_value(): void
    {
        $admin = User::factory()->create();
        $encryptedSecret = Crypt::encryptString('keep-this-client-secret');
        $boxNow = $this->persistedBoxNow([
            'boxnow_partner_id' => 'partner-test',
            'boxnow_oauth_client_secret_encrypted' => $encryptedSecret,
        ]);

        Livewire::actingAs($admin)
            ->test(ShippingManager::class, ['editPage' => true, 'recordId' => $boxNow->id])
            ->set('form.boxnow_api_url', 'https://api.boxnow.example')
            ->set('form.boxnow_oauth_client_secret', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $settings = $boxNow->refresh()->settings;
        $this->assertSame($encryptedSecret, $settings['boxnow_oauth_client_secret_encrypted']);
        $this->assertSame('https://api.boxnow.example', $settings['boxnow_api_url']);
    }

    public function test_admin_boxnow_rejects_an_invalid_api_url(): void
    {
        $admin = User::factory()->create();
        $boxNow = $this->persistedBoxNow([
            'boxnow_partner_id' => 'partner-test',
            'boxnow_api_url' => 'https://api-before.boxnow.example',
        ]);

        Livewire::actingAs($admin)
            ->test(ShippingManager::class, ['editPage' => true, 'recordId' => $boxNow->id])
            ->set('form.boxnow_api_url', 'ftp://api.boxnow.example')
            ->call('save')
            ->assertHasErrors(['form.boxnow_api_url' => 'url']);

        $this->assertSame('https://api-before.boxnow.example', $boxNow->refresh()->settings['boxnow_api_url']);
    }

    public function test_admin_sets_the_shared_island_policy_without_changing_method_activation(): void
    {
        $admin = User::factory()->create();
        $activationBefore = ShippingMethod::query()
            ->whereIn('code', ['mbe_mainland_hr', 'mbe_islands_hr', 'boxnow'])
            ->pluck('is_active', 'code')
            ->map(fn ($active): bool => (bool) $active)
            ->all();

        Livewire::actingAs($admin)
            ->test(ShippingManager::class)
            ->set('islandPolicy', CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS)
            ->call('saveIslandPolicy')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $this->assertSame(
            CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
            app(\App\Services\Settings\SystemSettingsService::class)->get(
                CroatianIslandDestinationClassifier::SETTING_KEY,
            ),
        );
        $this->assertSame(
            $activationBefore,
            ShippingMethod::query()
                ->whereIn('code', ['mbe_mainland_hr', 'mbe_islands_hr', 'boxnow'])
                ->pluck('is_active', 'code')
                ->map(fn ($active): bool => (bool) $active)
                ->all(),
        );

        Livewire::actingAs($admin)
            ->test(ShippingManager::class)
            ->set('islandPolicy', 'customer_decides')
            ->call('saveIslandPolicy')
            ->assertHasErrors(['islandPolicy']);
    }

    public function test_system_settings_seeder_does_not_reset_the_admin_island_policy(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->put(
            CroatianIslandDestinationClassifier::SETTING_KEY,
            CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
        );

        $this->seed(SystemSettingsSeeder::class);

        $this->assertSame(
            CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
            $settings->get(CroatianIslandDestinationClassifier::SETTING_KEY),
        );
    }

    public function test_gls_connector_hashes_password_stores_label_and_logs_retry_state(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://api.test.mygls.hr/ParcelService.svc/json/PrintLabels' => Http::response([
                'PrintLabelsErrorList' => [],
                'PrintLabelsInfoList' => [[
                    'ClientReference' => 'TERMOL-GLS-1',
                    'ParcelId' => 123456,
                    'ParcelNumber' => '008880001234',
                ]],
                'Labels' => array_values(unpack('C*', "%PDF-1.4\nTermol GLS test\n") ?: []),
            ]),
        ]);

        app(GlsApiService::class)->saveSettings([
            'gls_api_enabled' => true,
            'gls_api_mode' => 'test',
            'gls_api_username' => 'termol-test',
            'gls_api_password' => 'secret-password',
            'gls_api_client_number' => '100001',
            'gls_api_pickup_name' => 'Termol',
            'gls_api_pickup_contact_name' => 'Termol skladište',
            'gls_api_pickup_contact_phone' => '+3851000000',
            'gls_api_pickup_contact_email' => 'shop@example.test',
            'gls_api_pickup_street' => 'Skladišna 10',
            'gls_api_pickup_address_line_2' => '',
            'gls_api_pickup_city' => 'Zagreb',
            'gls_api_pickup_postal_code' => '10000',
            'gls_api_pickup_country_code' => 'HR',
            'gls_api_printer_type' => 'A4_2x2',
            'gls_api_print_position' => 1,
            'gls_api_show_print_dialog' => false,
            'gls_api_verify_tls' => true,
        ]);

        $order = $this->glsOrder();
        $shipment = app(GlsShipmentService::class)->send($order);

        $this->assertSame('008880001234', $shipment['parcel_number']);
        Storage::disk('local')->assertExists($shipment['label_path']);
        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'comment' => 'GLS pošiljka je kreirana iz administracije.',
        ]);

        $order->refresh();
        $this->assertNull(data_get($order->payload, 'gls.last_error'));
        $this->assertSame('008880001234', data_get($order->payload, 'gls.shipment.parcel_number'));

        $encrypted = (string) SystemSetting::query()
            ->where('key', 'gls_api_password_encrypted')
            ->value('value');
        $this->assertStringNotContainsString('secret-password', $encrypted);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.test.mygls.hr/ParcelService.svc/json/PrintLabels'
                && ($payload['Username'] ?? null) === 'termol-test'
                && is_array($payload['Password'] ?? null)
                && count($payload['Password']) === 64
                && data_get($payload, 'ParcelList.0.ServiceList.0.Code') === 'PSD'
                && data_get($payload, 'ParcelList.0.ServiceList.0.PSDParameter.StringValue') === 'HR-LOCKER-101';
        });
    }

    public function test_gls_parcel_shop_requires_a_selection_and_persists_it_on_the_order(): void
    {
        $product = Product::query()->create([
            'code' => 'gls-checkout-product',
            'sku' => 'GLS-CHECKOUT-PRODUCT',
            'is_active' => true,
            'base_price' => 50,
            'stock_qty' => 10,
            'weight_kg' => 2,
            'length_cm' => 20,
            'width_cm' => 20,
            'height_cm' => 20,
            'shipping_labels' => [],
        ]);
        $product->setRelation('categories', collect());
        $product->setRelation('packages', collect());

        $lines = collect([[
            'product' => $product,
            'translation' => null,
            'quantity' => 1,
            'base_unit_price' => 50,
            'unit_price' => 50,
            'display_unit_price' => 50,
            'line_discount_total' => 0,
            'line_tax_total' => 0,
            'tax_rate' => 0,
            'line_total' => 50,
        ]]);

        $cart = Mockery::mock(CartService::class);
        $cart->shouldReceive('lines')->andReturn($lines);
        $cart->shouldReceive('summary')->andReturn([
            'subtotal' => 50,
            'subtotal_after_discount' => 50,
            'discount_total' => 0,
            'tax_total' => 0,
            'tax_rate' => 0,
            'grand_total' => 50,
            'coupon_code' => '',
        ]);

        ShippingMethod::query()->create([
            'code' => 'gls_dpm_checkout',
            'name' => 'GLS paketomat / ParcelShop',
            'carrier' => 'gls',
            'service_type' => 'parcel_locker',
            'pricing_type' => 'flat',
            'price' => 4.99,
            'max_weight_kg' => 40,
            'max_length_cm' => 50,
            'max_width_cm' => 50,
            'max_height_cm' => 50,
            'allows_fragile' => false,
            'allows_oversized' => false,
            'allows_heavy' => false,
            'missing_measurements_policy' => 'block',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        PaymentMethod::query()->create([
            'code' => 'bank',
            'name' => 'Virman',
            'provider' => 'bank',
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        Currency::query()->create([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
            'symbol_position' => 'right',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        OrderStatus::query()->create([
            'code' => 'new',
            'name' => 'Nova',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $checkout = new CheckoutService($cart, app(ShippingCalculator::class));
        $payload = $this->checkoutPayload();

        try {
            $checkout->placeOrder($payload);
            $this->fail('GLS DPM checkout without a selected location should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shipping_gls_dpm_id', $exception->errors());
        }

        $order = $checkout->placeOrder(array_merge($payload, [
            'shipping_gls_dpm_id' => 'HR-LOCKER-202',
            'shipping_gls_dpm_external_id' => '202',
            'shipping_gls_dpm_name' => 'GLS Paketomat Centar',
            'shipping_gls_dpm_type' => 'parcel-locker',
            'shipping_gls_dpm_address_line_1' => 'Trg 1',
            'shipping_gls_dpm_postal_code' => '10000',
            'shipping_gls_dpm_city' => 'Zagreb',
        ]));

        $this->assertSame('HR-LOCKER-202', data_get($order->payload, 'shipping.gls_dpm.id'));
        $this->assertSame('GLS Paketomat Centar', data_get($order->payload, 'shipping.gls_dpm.name'));
        $this->assertSame(4.99, (float) $order->shipping_total);
    }

    public function test_superadmin_can_open_the_shipping_and_gls_admin_tabs(): void
    {
        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);

        $this->actingAs($admin)
            ->get(route('admin.shipping.index'))
            ->assertOk()
            ->assertSee('Metode i cjenici')
            ->assertSee('href="'.route('admin.shipping.create').'"', false)
            ->assertDontSee('wire:model="form.boxnow_partner_id"', false);

        $this->actingAs($admin)
            ->get(route('admin.shipping.index', ['tab' => 'gls']))
            ->assertOk()
            ->assertSee('MyGLS Croatia')
            ->assertSee('Uključi GLS API');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function shippingMethod(array $overrides = []): ShippingMethod
    {
        $method = new ShippingMethod(array_merge([
            'code' => 'test',
            'name' => 'Test delivery',
            'carrier' => 'manual',
            'service_type' => 'home_delivery',
            'pricing_type' => 'flat',
            'price' => 4.99,
            'allows_fragile' => true,
            'allows_oversized' => true,
            'allows_heavy' => true,
            'fragile_surcharge' => 0,
            'oversized_surcharge' => 0,
            'heavy_surcharge' => 0,
            'missing_measurements_policy' => 'allow',
            'is_active' => true,
        ], $overrides));
        $method->setRelation('rates', collect());

        return $method;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function persistedBoxNow(array $settings): ShippingMethod
    {
        return ShippingMethod::query()->create([
            'code' => 'boxnow',
            'name' => 'BOX NOW paketomat',
            'carrier' => 'boxnow',
            'service_type' => 'parcel_locker',
            'pricing_type' => 'flat',
            'price' => 2.99,
            'max_weight_kg' => 20,
            'max_length_cm' => 60,
            'max_width_cm' => 45,
            'max_height_cm' => 36,
            'allows_fragile' => false,
            'allows_oversized' => false,
            'allows_heavy' => false,
            'missing_measurements_policy' => 'block',
            'is_active' => true,
            'settings' => $settings,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides = []): Product
    {
        $product = new Product(array_merge([
            'code' => 'shipping-test-product',
            'sku' => 'SHIPPING-TEST-PRODUCT',
            'is_active' => true,
            'base_price' => 50,
            'stock_qty' => 20,
            'weight_kg' => 1,
            'length_cm' => 20,
            'width_cm' => 20,
            'height_cm' => 20,
            'shipping_labels' => [],
        ], $overrides));
        $product->forceFill(['id' => 101]);
        $product->setRelation('packages', collect());
        $product->setRelation('categories', collect());

        return $product;
    }

    private function lines(Product $product): \Illuminate\Support\Collection
    {
        return collect([[
            'product' => $product,
            'quantity' => 1,
        ]]);
    }

    private function glsOrder(): Order
    {
        return Order::query()->create([
            'order_number' => 'TERMOL-GLS-1',
            'source' => 'web',
            'locale' => 'hr',
            'currency_code' => 'EUR',
            'currency_rate' => 1,
            'customer_name' => 'Test Kupac',
            'customer_email' => 'kupac@example.test',
            'customer_phone' => '+385911234567',
            'billing_first_name' => 'Test',
            'billing_last_name' => 'Kupac',
            'billing_address_line_1' => 'Ilica 10',
            'billing_postal_code' => '10000',
            'billing_city' => 'Zagreb',
            'billing_country_code' => 'HR',
            'shipping_first_name' => 'Test',
            'shipping_last_name' => 'Kupac',
            'shipping_address_line_1' => 'Ilica 10',
            'shipping_postal_code' => '10000',
            'shipping_city' => 'Zagreb',
            'shipping_country_code' => 'HR',
            'payment_method_code' => 'bank',
            'payment_method_name' => 'Virman',
            'shipping_method_code' => 'gls_dpm',
            'shipping_method_name' => 'GLS paketomat / ParcelShop',
            'item_qty' => 1,
            'subtotal' => 50,
            'shipping_total' => 4.99,
            'payment_fee_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 54.99,
            'payload' => [
                'shipping' => [
                    'gls_dpm' => [
                        'id' => 'HR-LOCKER-101',
                        'external_id' => '101',
                        'name' => 'GLS Paketomat Zagreb',
                        'type' => 'parcel-locker',
                        'address_line_1' => 'Ilica 20',
                        'postal_code' => '10000',
                        'city' => 'Zagreb',
                    ],
                ],
            ],
            'placed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(): array
    {
        return [
            'customer_first_name' => 'Test',
            'customer_last_name' => 'Kupac',
            'customer_email' => 'kupac@example.test',
            'customer_phone' => '+385911234567',
            'billing_first_name' => 'Test',
            'billing_last_name' => 'Kupac',
            'billing_address_line_1' => 'Ilica 10',
            'billing_postal_code' => '10000',
            'billing_city' => 'Zagreb',
            'billing_country_code' => 'HR',
            'shipping_first_name' => 'Test',
            'shipping_last_name' => 'Kupac',
            'shipping_address_line_1' => 'Ilica 10',
            'shipping_postal_code' => '10000',
            'shipping_city' => 'Zagreb',
            'shipping_country_code' => 'HR',
            'shipping_method_code' => 'gls_dpm_checkout',
            'payment_method_code' => 'bank',
        ];
    }
}
