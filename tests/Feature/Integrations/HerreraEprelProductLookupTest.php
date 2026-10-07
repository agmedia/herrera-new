<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\Integrations\Msan\MsanProduct;
use App\Services\Integrations\Msan\EprelClient;
use App\Services\Integrations\Msan\EprelDeclarationWriter;
use App\Services\Integrations\Msan\EprelMatchConflictException;
use App\Services\Integrations\Msan\EprelProductIdentity;
use App\Services\Integrations\Msan\EprelProductLookupService;
use App\Services\Integrations\Msan\MsanSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraEprelProductLookupTest extends TestCase
{
    use RefreshDatabase;

    private const GTIN = '9120072372216';

    protected function setUp(): void
    {
        parent::setUp();

        app(MsanSettingsService::class)->saveAdminValues([
            'msan_enabled' => false,
            'msan_eprel_enabled' => true,
            'msan_eprel_api_key' => 'synthetic-herrera-eprel-key',
        ]);
        Http::preventStrayRequests();
    }

    public function test_criteria_read_original_model_and_valid_ean_without_a_supplier_link(): void
    {
        $product = $this->product(['model' => 'LX400170', 'ean' => self::GTIN]);
        $product->update(['sku' => 'LOCAL-ALIAS', 'barcode' => self::GTIN]);

        $criteria = app(EprelProductLookupService::class)->criteria($product);

        $this->assertSame([self::GTIN], $criteria['gtins']);
        $this->assertSame(['LX400170', 'LOCAL-ALIAS', $product->code], $criteria['models']);
        $this->assertSame(['Braytron'], $criteria['brands']);
        $this->assertSame([], $criteria['groups']);
        $this->assertSame(0, MsanProduct::query()->count());
        Http::assertNothingSent();
    }

    public function test_invalid_original_ean_and_non_scalar_model_are_not_lookup_candidates(): void
    {
        $product = $this->product(['model' => ['not' => 'an identifier'], 'ean' => '9120072372217']);
        $criteria = app(EprelProductLookupService::class)->criteria($product);

        $this->assertSame([], $criteria['gtins']);
        $this->assertSame([$product->code], $criteria['models']);
        Http::assertNothingSent();
    }

    public function test_explicit_model_override_remains_the_only_model_candidate(): void
    {
        $product = $this->product(['model' => 'LX400170']);
        $criteria = app(EprelProductLookupService::class)->criteria($product, ['model' => 'CONFIRMED-17']);

        $this->assertSame(['CONFIRMED-17'], $criteria['models']);
        Http::assertNothingSent();
    }

    public function test_original_ean_can_store_an_exact_official_declaration_with_msan_disabled(): void
    {
        $product = $this->product(['model' => 'LX400170', 'ean' => self::GTIN]);
        $originalPayload = $product->payload;
        Http::fake([
            EprelClient::BASE_URL.'/api/product/gtin/'.self::GTIN => Http::response([
                'hits' => [$this->record()],
            ]),
        ]);

        $outcome = app(EprelProductLookupService::class)->lookup($product);

        $this->assertSame(EprelProductLookupService::STATUS_MATCHED, $outcome['status']);
        $this->assertSame('gtin', $outcome['matched_by']);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            'eprel_registration_number' => '646868',
            'eprel_product_group' => 'lightsources',
            'energy_class' => 'F',
        ]);
        $this->assertSame($originalPayload, $product->fresh()->payload);
        $loaded = Product::query()->withStorefrontEnergyData()->findOrFail($product->id);
        $this->assertTrue($loaded->relationLoaded('energyDeclarations'));
        $this->assertSame('exact', $loaded->energyDeclarations->sole()->payload['match']);
        $this->assertTrue(EprelProductIdentity::matches($loaded, $loaded->energyDeclarations->sole()->payload['product_identity']));
        $this->assertFalse(app(MsanSettingsService::class)->enabled());
        $this->assertSame(0, MsanProduct::query()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->header('x-api-key') === ['synthetic-herrera-eprel-key']);
    }

    public function test_original_model_requires_exact_model_and_brand_in_the_selected_group(): void
    {
        $product = $this->product(['model' => 'LX400170']);
        $product->update(['eprel_lookup_product_group' => 'lightsources']);
        Http::fake(fn (Request $request) => Http::response(
            str_contains($request->url(), '/646868')
                ? $this->record()
                : ['hits' => [$this->record()]],
        ));

        $outcome = app(EprelProductLookupService::class)->lookup($product);

        $this->assertSame(EprelProductLookupService::STATUS_MATCHED, $outcome['status']);
        $this->assertSame('model_identifier', $outcome['matched_by']);
        $this->assertSame('646868', $product->fresh()->eprel_registration_number);
        Http::assertSent(fn (Request $request): bool => ($request->data()['modelIdentifier'] ?? null) === 'LX400170'
            && ($request->data()['supplierOrTrademark'] ?? null) === 'Braytron');
    }

    public function test_same_original_model_with_a_different_brand_does_not_create_a_declaration(): void
    {
        $product = $this->product(['model' => 'LX400170']);
        $product->update(['eprel_lookup_product_group' => 'lightsources']);
        Http::fake(fn () => Http::response(['hits' => [$this->record(['supplierOrTrademark' => 'OTHER BRAND'])]]));

        $outcome = app(EprelProductLookupService::class)->lookup($product);

        $this->assertSame(EprelProductLookupService::STATUS_NOT_FOUND, $outcome['status']);
        $this->assertSame(0, ProductEnergyDeclaration::query()->count());
        $this->assertNull($product->fresh()->energy_label_url);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/646868'));
    }

    public function test_conflicting_original_and_local_gtins_store_neither_match(): void
    {
        $product = $this->product(['model' => 'LX400170', 'ean' => self::GTIN]);
        $product->update(['barcode' => '4006381333931']);
        Http::fake([
            EprelClient::BASE_URL.'/api/product/gtin/'.self::GTIN => Http::response(['hits' => [$this->record()]]),
            EprelClient::BASE_URL.'/api/product/gtin/4006381333931' => Http::response(['hits' => [$this->record([
                'eprelRegistrationNumber' => '646869',
                'gtinIdentifier' => '4006381333931',
            ])]]),
        ]);

        try {
            app(EprelProductLookupService::class)->lookup($product);
            $this->fail('Conflicting original and local identifiers must not be stored.');
        } catch (EprelMatchConflictException) {
            $this->assertSame(0, ProductEnergyDeclaration::query()->count());
            $this->assertNull($product->fresh()->eprel_registration_number);
        }
        Http::assertSentCount(2);
    }

    public function test_original_identifier_change_during_lookup_invalidates_the_match(): void
    {
        $product = $this->product(['model' => 'LX400170', 'ean' => self::GTIN]);
        Http::fake(function () use ($product) {
            $payload = $product->payload;
            $payload['opencart']['model'] = 'CHANGED-18';
            Product::query()->whereKey($product->id)->update(['payload' => $payload]);

            return Http::response(['hits' => [$this->record()]]);
        });

        try {
            app(EprelProductLookupService::class)->lookup($product);
            $this->fail('Changed source identity must invalidate the pending match.');
        } catch (EprelMatchConflictException) {
            $this->assertSame(0, ProductEnergyDeclaration::query()->count());
            $this->assertNull($product->fresh()->eprel_registration_number);
        }
    }

    public function test_source_identity_race_is_rejected_even_when_explicit_model_override_keeps_criteria_unchanged(): void
    {
        $product = $this->product(['model' => 'LX400170', 'ean' => self::GTIN]);
        Http::fake(function () use ($product) {
            $payload = $product->payload;
            $payload['opencart']['model'] = 'CHANGED-18';
            Product::query()->whereKey($product->id)->update(['payload' => $payload]);

            return Http::response(['hits' => [$this->record()]]);
        });

        try {
            app(EprelProductLookupService::class)->lookup($product, ['model' => 'LX400170']);
            $this->fail('A model override cannot bypass a changed source identity.');
        } catch (EprelMatchConflictException) {
            $this->assertSame(0, ProductEnergyDeclaration::query()->count());
            $this->assertNull($product->fresh()->eprel_registration_number);
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('identityChanges')]
    public function test_confirmed_fingerprint_becomes_stale_after_a_native_or_source_identity_change(string $field, mixed $value): void
    {
        $product = $this->product(['model' => 'LX400170', 'ean' => self::GTIN]);
        Http::fake([
            EprelClient::BASE_URL.'/api/product/gtin/'.self::GTIN => Http::response(['hits' => [$this->record()]]),
        ]);
        app(EprelProductLookupService::class)->lookup($product);
        $product = $product->fresh();
        $fingerprint = $product->energyDeclarations()->sole()->payload['product_identity'];
        $this->assertTrue(EprelProductIdentity::matches($product, $fingerprint));

        if (str_starts_with($field, 'opencart.')) {
            $payload = $product->payload;
            data_set($payload, $field, $value);
            $product->payload = $payload;
        } else {
            $product->{$field} = $value;
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertFalse(EprelProductIdentity::matches($product, $fingerprint));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        Http::assertSentCount(1);
    }

    public static function identityChanges(): array
    {
        return [
            'native code' => ['code', 'CHANGED-CODE'],
            'native sku' => ['sku', 'CHANGED-SKU'],
            'native barcode' => ['barcode', '4006381333931'],
            'native brand' => ['manufacturer_id', 999],
            'original model' => ['opencart.model', 'CHANGED-MODEL'],
            'original ean' => ['opencart.ean', '4006381333931'],
        ];
    }

    public function test_fingerprint_ignores_price_stock_and_unrelated_payload_but_rejects_missing_or_malformed_proof(): void
    {
        $product = $this->product(['model' => 'LX400170', 'ean' => self::GTIN]);
        $fingerprint = EprelProductIdentity::fingerprint($product);
        $product->base_price = '10.0000';
        $product->stock_qty = 80;
        $product->supplier_stock_qty = 100;
        $product->payload = $product->payload + ['business_note' => 'Unrelated update'];

        $this->assertTrue(EprelProductIdentity::matches($product, $fingerprint));
        foreach ([null, '', [], true, 'exact', str_repeat('a', 64), 'v1:'.str_repeat('a', 64)] as $invalid) {
            $this->assertFalse(EprelProductIdentity::matches($product, $invalid));
        }
        Http::assertNothingSent();
    }

    public function test_writer_rechecks_the_full_identity_under_its_product_lock_before_any_declaration_write(): void
    {
        $product = $this->product(['model' => 'LX400170', 'ean' => self::GTIN]);
        $expected = EprelProductIdentity::fingerprint($product);
        $payload = $product->payload;
        $payload['opencart']['model'] = 'CHANGED-MODEL';
        $product->update(['payload' => $payload]);

        try {
            app(EprelDeclarationWriter::class)->store($product->id, [
                'eprel_registration_number' => '646868',
                'eprel_product_group' => 'lightsources',
                'model_identifier' => 'LX400170',
                'energy_class' => 'F',
                'scale_min' => 'A',
                'scale_max' => 'G',
                'energy_label_image' => null,
                'energy_label_url' => null,
                'product_information_sheet_url' => null,
            ], expectedProductIdentity: ['product_identity' => $expected]);
            $this->fail('A stale identity must be checked again inside the writer transaction.');
        } catch (EprelMatchConflictException) {
            $this->assertSame(0, ProductEnergyDeclaration::query()->count());
            $this->assertNull($product->fresh()->eprel_registration_number);
        }
        Http::assertNothingSent();
    }

    private function product(array $original): Product
    {
        $manufacturer = Manufacturer::query()->create(['code' => 'BRAYTRON', 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => 'Braytron', 'slug' => 'braytron']);

        return Product::query()->create([
            'code' => 'herrera-oc-product-17',
            'manufacturer_id' => $manufacturer->id,
            'is_active' => true,
            'stock_qty' => 0,
            'base_price' => '20.4800',
            'payload' => ['opencart' => $original + ['product_id' => 17]],
        ]);
    }

    private function record(array $overrides = []): array
    {
        return $overrides + [
            'eprelRegistrationNumber' => '646868',
            'productGroup' => 'LIGHT_SOURCE',
            'modelIdentifier' => 'LX400170',
            'supplierOrTrademark' => 'Braytron',
            'gtinIdentifier' => self::GTIN,
            'energyClass' => 'F',
            'scaleMin' => 'A',
            'scaleMax' => 'G',
        ];
    }
}
