<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\Msan\SyncEprelEnergyJob;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\EprelClient;
use App\Services\Integrations\Msan\EprelDeclarationWriter;
use App\Services\Integrations\Msan\EprelEnergySyncService;
use App\Services\Integrations\Msan\EprelProductLookupService;
use App\Services\Integrations\Msan\MsanCatalogSyncCoordinator;
use App\Services\Integrations\Msan\MsanSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class EprelEnergySyncTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'refrigeratingappliances2019';

    private const GROUP_CODE = 'HOUSEHOLD_REFRIGERATING_APPLIANCE_2019';

    protected function setUp(): void
    {
        parent::setUp();

        app(MsanSettingsService::class)->saveAdminValues([
            'msan_enabled' => true,
            'msan_eprel_enabled' => true,
            'msan_eprel_api_key' => 'synthetic-eprel-key',
            'msan_eprel_connect_timeout' => 5,
            'msan_eprel_timeout' => 15,
        ]);
        Http::preventStrayRequests();
    }

    public function test_client_uses_only_the_official_host_and_normalizes_an_exact_registration_match(): void
    {
        Http::fake([
            EprelClient::BASE_URL.'/api/products/'.self::GROUP.'/1234567' => Http::response([
                'eprelRegistrationNumber' => 1234567,
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => 'COOL-1000',
                'energyClass' => 'C',
                'scaleMin' => 'A',
                'scaleMax' => 'G',
                'energyClassImageWithScale' => 'C-Left-LightOrange-WithAGScale.svg',
                'productInformationSheetUrl' => '/api/products/'.self::GROUP.'/1234567/product-information-sheet?format=PDF',
            ]),
        ]);

        $result = app(EprelClient::class)->findByRegistrationNumber(self::GROUP, '1234567');

        $this->assertNotNull($result);
        $this->assertSame('1234567', $result['eprel_registration_number']);
        $this->assertSame(self::GROUP, $result['eprel_product_group']);
        $this->assertSame('COOL-1000', $result['model_identifier']);
        $this->assertSame('C', $result['energy_class']);
        $this->assertSame('A', $result['scale_min']);
        $this->assertSame('G', $result['scale_max']);
        $this->assertSame('C-Left-LightOrange-WithAGScale.svg', $result['energy_label_image']);
        $this->assertSame(
            EprelClient::BASE_URL.'/api/products/'.self::GROUP.'/1234567/labels?format=PDF',
            $result['energy_label_url'],
        );
        $this->assertSame(
            EprelClient::BASE_URL.'/fiches/'.self::GROUP.'/Fiche_1234567_HR.pdf',
            $result['product_information_sheet_url'],
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === EprelClient::BASE_URL.'/api/products/'.self::GROUP.'/1234567'
                && $request->header('x-api-key') === ['synthetic-eprel-key'];
        });
    }

    public function test_client_resolves_a_global_registration_number_only_with_a_whitelisted_response_group(): void
    {
        Http::fake([
            EprelClient::BASE_URL.'/api/product/1234567' => Http::response([
                'eprelRegistrationNumber' => '1234567',
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => 'GLOBAL-COOL-1000',
                'energyClass' => 'D',
            ]),
            EprelClient::BASE_URL.'/api/product/7654321' => Http::response([
                'eprelRegistrationNumber' => '7654321',
                'productGroup' => '../unsupported-group',
                'modelIdentifier' => 'UNSAFE-GROUP',
            ]),
        ]);

        $result = app(EprelClient::class)->findByRegistrationNumber('1234567');

        $this->assertNotNull($result);
        $this->assertSame('1234567', $result['eprel_registration_number']);
        $this->assertSame(self::GROUP, $result['eprel_product_group']);
        $this->assertSame('GLOBAL-COOL-1000', $result['model_identifier']);
        $this->assertNull(app(EprelClient::class)->findByRegistrationNumber('7654321'));
        Http::assertSent(static fn (Request $request): bool => $request->url()
            === EprelClient::BASE_URL.'/api/product/1234567');
        Http::assertSent(static fn (Request $request): bool => $request->url()
            === EprelClient::BASE_URL.'/api/product/7654321');
    }

    public function test_manual_registration_search_needs_no_group_and_accepts_only_the_exact_number(): void
    {
        $this->assertSame(
            'EPREL registracijski broj',
            EprelClient::searchByOptions()[EprelClient::SEARCH_REGISTRATION_NUMBER],
        );

        $product = Product::query()->create([
            'code' => 'MANUAL-EPREL-REGISTRATION',
            'sku' => 'MANUAL-EPREL-REGISTRATION',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
        ]);
        Http::fake([
            EprelClient::BASE_URL.'/api/product/2210952' => Http::response([
                'eprelRegistrationNumber' => '2210952',
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => 'DD-235E S',
                'energyClass' => 'E',
            ]),
        ]);

        $result = app(EprelProductLookupService::class)->lookup($product, [
            'search_by' => EprelClient::SEARCH_REGISTRATION_NUMBER,
            'search_query' => ' 2210952 ',
            'eprel_product_group' => '',
        ]);

        $this->assertSame(EprelProductLookupService::STATUS_MATCHED, $result['status']);
        $this->assertSame('registration_number', $result['matched_by']);
        $this->assertSame('2210952', $result['data']['eprel_registration_number']);
        $this->assertSame(self::GROUP, $result['data']['eprel_product_group']);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            'eprel_registration_number' => '2210952',
            'eprel_product_group' => self::GROUP,
        ]);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url()
            === EprelClient::BASE_URL.'/api/product/2210952');

        $otherProduct = Product::query()->create([
            'code' => 'MANUAL-EPREL-MISMATCH',
            'sku' => 'MANUAL-EPREL-MISMATCH',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
        ]);
        Http::fake([
            EprelClient::BASE_URL.'/api/product/7654321' => Http::response([
                'eprelRegistrationNumber' => '9999999',
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => 'WRONG-REGISTRATION',
            ]),
        ]);

        $mismatch = app(EprelProductLookupService::class)->lookup($otherProduct, [
            'search_by' => EprelClient::SEARCH_REGISTRATION_NUMBER,
            'search_query' => '7654321',
        ]);

        $this->assertSame(EprelProductLookupService::STATUS_NOT_FOUND, $mismatch['status']);
        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $otherProduct->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
    }

    public function test_gtin_lookup_validates_the_check_digit_and_accepts_one_unique_exact_product(): void
    {
        $gtin = '9120072372216';
        Http::fake([
            EprelClient::BASE_URL.'/api/product/gtin/'.$gtin => Http::response([
                'size' => 3,
                'hits' => [
                    [
                        'eprelRegistrationNumber' => '646868',
                        'productGroup' => 'ELECTRONIC_DISPLAY',
                        'modelIdentifier' => 'M27FC12401',
                        'supplierOrTrademark' => 'THOMSON',
                        // The exact GTIN resolver does not always repeat the
                        // identifier in its response records.
                        'energyClass' => 'F',
                    ],
                    [
                        // The resolver may repeat a registration; uniqueness is
                        // defined by the strict group and registration identity.
                        'eprelRegistrationNumber' => '646868',
                        'productGroup' => 'electronicdisplays',
                        'modelIdentifier' => 'M27FC12401',
                        'gtinIdentifier' => $gtin,
                    ],
                    [
                        'eprelRegistrationNumber' => '999999',
                        'productGroup' => 'ELECTRONIC_DISPLAY',
                        'modelIdentifier' => 'OTHER-GTIN',
                        'gtinIdentifier' => '9120072372209',
                    ],
                ],
            ]),
        ]);

        $result = app(EprelClient::class)->findByGtinIdentifier($gtin);

        $this->assertNotNull($result);
        $this->assertSame('646868', $result['eprel_registration_number']);
        $this->assertSame('electronicdisplays', $result['eprel_product_group']);
        $this->assertSame('M27FC12401', $result['model_identifier']);

        Http::fake();
        try {
            app(EprelClient::class)->findByGtinIdentifier('9120072372217');
            $this->fail('A GTIN with an invalid check digit must be rejected.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_gtin_lookup_rejects_multiple_unique_exact_products(): void
    {
        $gtin = '9120072372216';
        Http::fake([
            EprelClient::BASE_URL.'/api/product/gtin/'.$gtin => Http::response([
                'hits' => [
                    [
                        'eprelRegistrationNumber' => '646868',
                        'productGroup' => 'ELECTRONIC_DISPLAY',
                        'gtinIdentifier' => $gtin,
                    ],
                    [
                        'eprelRegistrationNumber' => '646869',
                        'productGroup' => 'ELECTRONIC_DISPLAY',
                        'gtinIdentifier' => $gtin,
                    ],
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('više artikala s istim GTIN identifikatorom');

        app(EprelClient::class)->findByGtinIdentifier($gtin);
    }

    public function test_model_lookup_accepts_only_one_exact_match_and_rejects_unofficial_document_urls(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/products/'.self::GROUP.'/7654321')) {
                return Http::response([
                    'eprelRegistrationNumber' => '7654321',
                    'productGroup' => self::GROUP_CODE,
                    'modelIdentifier' => 'MODEL-X',
                    'energyEfficiencyClass' => 'B',
                    'energyClassImage' => 'B-Left-MediumGreen.png',
                    'productInformationSheetUrl' => 'https://malicious.invalid/fake.pdf',
                ]);
            }

            return Http::response([
                'size' => 2,
                'hits' => [
                    [
                        'eprelRegistrationNumber' => '1111111',
                        'productGroup' => self::GROUP_CODE,
                        'modelIdentifier' => 'MODEL-X-PRO',
                    ],
                    [
                        'eprelRegistrationNumber' => '7654321',
                        'productGroup' => self::GROUP_CODE,
                        'modelIdentifier' => 'MODEL-X',
                    ],
                ],
            ]);
        });

        $result = app(EprelClient::class)->findByModelIdentifier(self::GROUP, 'MODEL-X');

        $this->assertNotNull($result);
        $this->assertSame('7654321', $result['eprel_registration_number']);
        $this->assertSame('MODEL-X', $result['model_identifier']);
        $this->assertSame('B-Left-MediumGreen.png', $result['energy_label_image']);
        $this->assertSame(
            EprelClient::BASE_URL.'/fiches/'.self::GROUP.'/Fiche_7654321_HR.pdf',
            $result['product_information_sheet_url'],
        );
        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => str_starts_with(
            $request->url(),
            EprelClient::BASE_URL.'/api/products/'.self::GROUP.'?',
        ) && str_contains($request->url(), 'modelIdentifier=MODEL-X'));
    }

    public function test_model_lookup_uses_one_brand_candidate_and_filters_it_exactly_after_normalization(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/products/'.self::GROUP.'/2222222')) {
                return Http::response([
                    'eprelRegistrationNumber' => '2222222',
                    'productGroup' => self::GROUP_CODE,
                    'modelIdentifier' => 'SHARED-MODEL',
                    'supplierOrTrademark' => 'ACME EUROPE',
                    'energyClass' => 'C',
                ]);
            }

            return Http::response([
                'hits' => [
                    [
                        'eprelRegistrationNumber' => '1111111',
                        'productGroup' => self::GROUP_CODE,
                        'modelIdentifier' => 'SHARED-MODEL',
                        'supplierOrTrademark' => 'Other brand',
                    ],
                    [
                        'eprelRegistrationNumber' => '2222222',
                        'productGroup' => self::GROUP_CODE,
                        'modelIdentifier' => 'SHARED-MODEL',
                        'supplierOrTrademark' => 'acme europe',
                    ],
                ],
            ]);
        });

        $result = app(EprelClient::class)->findByModelIdentifier(
            self::GROUP,
            'SHARED-MODEL',
            ['  Acme   Europe  ', 'ACME EUROPE'],
        );

        $this->assertNotNull($result);
        $this->assertSame('2222222', $result['eprel_registration_number']);
        Http::assertSent(static fn (Request $request): bool => str_starts_with(
            $request->url(),
            EprelClient::BASE_URL.'/api/products/'.self::GROUP.'?',
        ) && str_contains($request->url(), 'supplierOrTrademark=Acme%20Europe'));
    }

    public function test_model_lookup_uses_the_official_one_based_contract_and_retries_a_failed_brand_filter_safely(): void
    {
        Http::fake(function (Request $request) {
            if ($request->url() === EprelClient::BASE_URL.'/api/products/'.self::GROUP.'/2222222') {
                return Http::response([
                    'eprelRegistrationNumber' => '2222222',
                    'productGroup' => self::GROUP_CODE,
                    'modelIdentifier' => 'DD-235E S',
                    'supplierOrTrademark' => 'VIVAX',
                    'energyClass' => 'C',
                ]);
            }

            if (str_contains($request->url(), 'supplierOrTrademark=VIVAX')) {
                return Http::response(['message' => 'Internal server error'], 500);
            }

            return Http::response(['size' => 1, 'hits' => [[
                'eprelRegistrationNumber' => '2222222',
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => 'DD-235E S',
                'supplierOrTrademark' => 'VIVAX',
            ]]]);
        });

        $result = app(EprelClient::class)->findByModelIdentifier(
            self::GROUP,
            'DD-235E S',
            ['VIVAX'],
        );

        $this->assertNotNull($result);
        $this->assertSame('2222222', $result['eprel_registration_number']);
        $requests = collect(Http::recorded())->map(
            static fn (array $record): string => $record[0]->url(),
        )->values();
        $this->assertCount(3, $requests);
        $this->assertStringContainsString('_page=1', $requests[0]);
        $this->assertStringContainsString('genericField=MODEL_IDENTIFIER', $requests[0]);
        $this->assertStringContainsString('modelIdentifier=DD-235E%20S', $requests[0]);
        $this->assertStringContainsString('supplierOrTrademark=VIVAX', $requests[0]);
        $this->assertStringContainsString('applianceType=ANY', $requests[0]);
        $this->assertStringNotContainsString('supplierOrTrademark=', $requests[1]);
        $this->assertStringNotContainsString('_page=0', $requests[1]);
    }

    public function test_official_brand_search_rejects_multiple_exact_products(): void
    {
        Http::fake([
            EprelClient::BASE_URL.'/api/products/'.self::GROUP.'*' => Http::response([
                'size' => 2,
                'hits' => [
                    [
                        'eprelRegistrationNumber' => '1111111',
                        'productGroup' => self::GROUP_CODE,
                        'supplierOrTrademark' => 'VIVAX',
                    ],
                    [
                        'eprelRegistrationNumber' => '2222222',
                        'productGroup' => self::GROUP_CODE,
                        'supplierOrTrademark' => 'vivax',
                    ],
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('više točnih artikala');

        app(EprelClient::class)->findBySearchCriterion(
            self::GROUP,
            EprelClient::SEARCH_BRAND_OR_TRADEMARK,
            ' VIVAX ',
        );
    }

    public function test_official_gtin_search_uses_the_selected_group_and_exact_search_field(): void
    {
        $gtin = '9120072372216';
        Http::fake(function (Request $request) use ($gtin) {
            if ($request->url() === EprelClient::BASE_URL.'/api/products/'.self::GROUP.'/646868') {
                return Http::response([
                    'eprelRegistrationNumber' => '646868',
                    'productGroup' => self::GROUP_CODE,
                    'modelIdentifier' => 'GTIN-MODEL',
                    // Some detail responses omit the repeated GTIN.
                    'energyClass' => 'D',
                ]);
            }

            return Http::response(['size' => 1, 'hits' => [[
                'eprelRegistrationNumber' => '646868',
                'productGroup' => self::GROUP_CODE,
                'gtinIdentifier' => $gtin,
            ]]]);
        });

        $result = app(EprelClient::class)->findBySearchCriterion(
            self::GROUP,
            EprelClient::SEARCH_GTIN_IDENTIFIER,
            $gtin,
        );

        $this->assertNotNull($result);
        $this->assertSame('646868', $result['eprel_registration_number']);
        Http::assertSent(static fn (Request $request): bool => str_starts_with(
            $request->url(),
            EprelClient::BASE_URL.'/api/products/'.self::GROUP.'?',
        )
            && str_contains($request->url(), '_page=1')
            && str_contains($request->url(), 'genericField=GTIN_IDENTIFIER')
            && str_contains($request->url(), 'gtinIdentifier='.$gtin)
            && str_contains($request->url(), 'applianceType=ANY'));
    }

    public function test_model_lookup_rejects_a_truncated_result_page_before_using_a_match(): void
    {
        Http::fake([
            EprelClient::BASE_URL.'/api/products/'.self::GROUP.'*' => Http::response([
                'size' => 101,
                'hits' => [[
                    'eprelRegistrationNumber' => '2222222',
                    'productGroup' => self::GROUP_CODE,
                    'modelIdentifier' => 'SHARED-MODEL',
                    'supplierOrTrademark' => 'ACME',
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('više rezultata nego što se može sigurno provjeriti');

        app(EprelClient::class)->findByModelIdentifier(
            self::GROUP,
            'SHARED-MODEL',
            ['ACME'],
        );
    }

    public function test_client_rejects_unmapped_product_group_before_any_request(): void
    {
        Http::fake();

        try {
            app(EprelClient::class)->findByRegistrationNumber('../other-host', '1234567');
            $this->fail('An unsupported EPREL group must be rejected.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_sync_is_bounded_to_selected_or_imported_local_products_and_preserves_manual_primary_data(): void
    {
        $category = MsanCategory::query()->create([
            'external_id' => 'EPREL-CATEGORY',
            'name' => 'Hladnjaci',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::GROUP,
            'energy_requirement' => 'required',
        ]);

        [$selectedProduct, $selectedSource] = $this->linkedSource('EPREL-SELECTED', '1234567', true, MsanProduct::IMPORT_PENDING);
        $selectedSource->categories()->attach($category->id, ['last_seen_at' => now()]);
        ProductEnergyDeclaration::query()->create([
            'product_id' => $selectedProduct->id,
            'context_code' => 'msan-primary',
            'label' => 'M SAN detekcija',
            'energy_class' => 'A',
            'energy_label_url' => 'https://supplier.example.test/label.svg',
            'product_information_sheet_url' => 'https://supplier.example.test/sheet.pdf',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
        ]);

        [$manualProduct, $importedSource] = $this->linkedSource('EPREL-IMPORTED', '7654321', false, MsanProduct::IMPORT_IMPORTED);
        $importedSource->categories()->attach($category->id, ['last_seen_at' => now()]);
        $manualProduct->forceFill([
            'energy_efficiency_class' => 'D',
            'energy_efficiency_scale' => 'A-G',
            'energy_label_url' => 'https://manual.example.test/label.svg',
            'product_information_sheet_url' => 'https://manual.example.test/sheet.pdf',
        ])->save();
        ProductEnergyDeclaration::query()->create([
            'product_id' => $manualProduct->id,
            'context_code' => 'manual-primary',
            'label' => 'Ručni unos',
            'energy_class' => 'D',
            'energy_label_url' => 'https://manual.example.test/label.svg',
            'product_information_sheet_url' => 'https://manual.example.test/sheet.pdf',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);

        [$unselectedProduct, $unselectedSource] = $this->linkedSource(
            'EPREL-NOT-SELECTED',
            '8888888',
            false,
            MsanProduct::IMPORT_PENDING,
        );
        $unselectedSource->categories()->attach($category->id, ['last_seen_at' => now()]);

        Http::fake(function (Request $request) {
            $registration = str_contains($request->url(), '/7654321') ? '7654321' : '1234567';

            return Http::response([
                'eprelRegistrationNumber' => $registration,
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => 'MODEL-'.$registration,
                'energyClass' => $registration === '1234567' ? 'C' : 'B',
                'energyClassRange' => 'A-G',
                'energyClassImageWithScale' => 'C-Left-LightOrange-WithAGScale.svg',
                'productInformationSheetUrl' => '/documents/'.$registration.'/sheet.pdf',
            ]);
        });

        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        app(EprelEnergySyncService::class)->sync($run);

        $run->refresh();
        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->processed_count);
        $this->assertSame(2, $run->succeeded_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertSame(2, $run->summary['exact_matches']);
        $this->assertStringNotContainsString('synthetic-eprel-key', json_encode($run->toArray(), JSON_THROW_ON_ERROR));
        Http::assertSentCount(2);
        $this->assertSame(MsanProduct::EPREL_EXACT, $selectedSource->refresh()->eprel_match_status);
        $this->assertNotNull($selectedSource->eprel_checked_at);

        $selectedProduct->refresh();
        $this->assertSame('C', $selectedProduct->energy_efficiency_class);
        $this->assertSame('A-G', $selectedProduct->energy_efficiency_scale);
        $this->assertSame('1234567', $selectedProduct->eprel_registration_number);
        $this->assertSame('C-Left-LightOrange-WithAGScale.svg', $selectedProduct->eprel_energy_label_image);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $selectedProduct->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            'eprel_registration_number' => '1234567',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $selectedProduct->id,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
            'is_primary' => false,
        ]);

        $manualProduct->refresh();
        $this->assertSame('D', $manualProduct->energy_efficiency_class);
        $this->assertSame('https://manual.example.test/label.svg', $manualProduct->energy_label_url);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $manualProduct->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            'eprel_registration_number' => '7654321',
            'is_primary' => false,
        ]);
        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $unselectedProduct->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);

        // A successful exact declaration is fresh and must not cause another
        // API request on the next manual run.
        Http::fake();
        $secondRun = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        app(EprelEnergySyncService::class)->sync($secondRun);
        $this->assertSame(0, $secondRun->refresh()->total_count);
        Http::assertNothingSent();
    }

    public function test_msan_no_match_does_not_delete_an_admin_verified_eprel_declaration(): void
    {
        $product = Product::query()->create([
            'code' => 'ADMIN-EPREL-PROTECTED',
            'sku' => 'ADMIN-EPREL-PROTECTED',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
        ]);
        app(EprelDeclarationWriter::class)->store($product->id, [
            'eprel_registration_number' => '646868',
            'eprel_product_group' => 'electronicdisplays',
            'model_identifier' => 'ADMIN-MODEL',
            'energy_class' => 'F',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'energy_label_image' => null,
            'energy_label_url' => EprelClient::BASE_URL.'/api/products/electronicdisplays/646868/labels?format=PDF',
            'product_information_sheet_url' => EprelClient::BASE_URL.'/fiches/electronicdisplays/Fiche_646868_HR.pdf',
        ]);
        $category = MsanCategory::query()->create([
            'external_id' => 'WRONG-MSAN-GROUP',
            'name' => 'Pogrešno mapirana kategorija',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::GROUP,
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
        ]);
        $source = MsanProduct::query()->create([
            'external_code' => 'WRONG-MSAN-GROUP-SOURCE',
            'name' => 'Pogrešno mapirani izvor',
            'selected' => true,
            'is_stale' => false,
            'local_product_id' => $product->id,
            'import_status' => MsanProduct::IMPORT_PENDING,
            'last_seen_at' => now(),
        ]);
        $source->categories()->attach($category->id, ['last_seen_at' => now()]);
        Http::fake(fn () => Http::response([], 404));
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);

        app(EprelEnergySyncService::class)->sync($run);

        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            'eprel_registration_number' => '646868',
        ]);
        $product->refresh();
        $this->assertSame('646868', $product->eprel_registration_number);
        $this->assertSame('electronicdisplays', $product->eprel_product_group);
        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->refresh()->status);
    }

    public function test_replacing_an_eprel_identity_does_not_mix_in_old_energy_values(): void
    {
        $product = Product::query()->create([
            'code' => 'EPREL-IDENTITY-REPLACEMENT',
            'sku' => 'EPREL-IDENTITY-REPLACEMENT',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
            'energy_efficiency_class' => 'B',
            'energy_efficiency_scale' => 'A-G',
            'eprel_registration_number' => '1111111',
            'eprel_product_group' => self::GROUP,
            'eprel_energy_label_image' => 'B-old.svg',
        ]);

        app(EprelDeclarationWriter::class)->store($product->id, [
            'eprel_registration_number' => '2222222',
            'eprel_product_group' => 'electronicdisplays',
            'model_identifier' => 'NEW-MODEL',
            'energy_class' => null,
            'scale_min' => null,
            'scale_max' => null,
            'energy_label_image' => null,
            'energy_label_url' => EprelClient::BASE_URL.'/api/products/electronicdisplays/2222222/labels?format=PDF',
            'product_information_sheet_url' => EprelClient::BASE_URL.'/fiches/electronicdisplays/Fiche_2222222_HR.pdf',
        ]);

        $product->refresh();
        $this->assertSame('2222222', $product->eprel_registration_number);
        $this->assertSame('electronicdisplays', $product->eprel_product_group);
        $this->assertNull($product->energy_efficiency_class);
        $this->assertNull($product->energy_efficiency_scale);
        $this->assertNull($product->eprel_energy_label_image);
    }

    public function test_background_sync_rechecks_source_identity_inside_the_writer_transaction(): void
    {
        $product = Product::query()->create([
            'code' => 'EPREL-SOURCE-RACE',
            'sku' => 'EPREL-SOURCE-RACE',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
        ]);
        $category = MsanCategory::query()->create([
            'external_id' => 'EPREL-SOURCE-RACE-CATEGORY',
            'name' => 'Hladnjaci s promjenjivim identitetom',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::GROUP,
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
        ]);
        $source = MsanProduct::query()->create([
            'external_code' => 'EPREL-SOURCE-RACE-SOURCE',
            'name' => 'ACME hladnjak',
            'brand' => 'ACME',
            'model' => 'RACE-MODEL',
            'selected' => true,
            'is_stale' => false,
            'local_product_id' => $product->id,
            'import_status' => MsanProduct::IMPORT_PENDING,
            'last_seen_at' => now(),
        ]);
        $source->categories()->attach($category->id, ['last_seen_at' => now()]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/1234567')) {
                return Http::response([
                    'eprelRegistrationNumber' => '1234567',
                    'productGroup' => self::GROUP_CODE,
                    'modelIdentifier' => 'RACE-MODEL',
                    'supplierOrTrademark' => 'ACME',
                    'energyClass' => 'C',
                ]);
            }

            return Http::response(['size' => 1, 'hits' => [[
                'eprelRegistrationNumber' => '1234567',
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => 'RACE-MODEL',
                'supplierOrTrademark' => 'ACME',
            ]]]);
        });

        $writer = new class((int) $source->id) extends EprelDeclarationWriter
        {
            public function __construct(private readonly int $sourceId) {}

            public function store(
                int $productId,
                array $data,
                string $origin = self::ORIGIN_ADMIN_LOOKUP,
                array $expectedProductIdentity = [],
                ?callable $identityGuard = null,
            ): ProductEnergyDeclaration {
                MsanProduct::query()->whereKey($this->sourceId)->update(['brand' => 'CHANGED-BRAND']);

                return parent::store($productId, $data, $origin, $expectedProductIdentity, $identityGuard);
            }
        };
        $service = new EprelEnergySyncService(
            app(EprelClient::class),
            app(MsanSettingsService::class),
            $writer,
        );
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);

        $service->sync($run);

        $source->refresh();
        $this->assertSame('CHANGED-BRAND', $source->brand);
        $this->assertSame(MsanProduct::EPREL_PENDING, $source->eprel_match_status);
        $this->assertNull($source->eprel_identifier_checksum);
        $this->assertNull($source->eprel_checked_at);
        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
        $run->refresh();
        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(0, $run->succeeded_count);
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame(1, $run->summary['invalid_local_data']);
    }

    public function test_background_sync_uses_the_selected_brand_for_an_exact_model_fallback(): void
    {
        $product = Product::query()->create([
            'code' => 'MODEL-BRAND-FALLBACK',
            'sku' => 'MODEL-BRAND-FALLBACK',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
        ]);
        $category = MsanCategory::query()->create([
            'external_id' => 'MODEL-BRAND-CATEGORY',
            'name' => 'Hladnjaci po modelu',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::GROUP,
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
        ]);
        $source = MsanProduct::query()->create([
            'external_code' => 'MODEL-BRAND-SOURCE',
            'name' => 'ACME hladnjak',
            'brand' => 'ACME',
            'model' => 'COOL-EXACT',
            'selected' => true,
            'is_stale' => false,
            'local_product_id' => $product->id,
            'import_status' => MsanProduct::IMPORT_PENDING,
            'last_seen_at' => now(),
        ]);
        $source->categories()->attach($category->id, ['last_seen_at' => now()]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/1234567')) {
                return Http::response([
                    'eprelRegistrationNumber' => '1234567',
                    'productGroup' => self::GROUP_CODE,
                    'modelIdentifier' => 'COOL-EXACT',
                    'supplierOrTrademark' => 'ACME',
                    'energyClass' => 'C',
                ]);
            }

            return Http::response(['size' => 1, 'hits' => [[
                'eprelRegistrationNumber' => '1234567',
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => 'COOL-EXACT',
                'supplierOrTrademark' => 'ACME',
            ]]]);
        });
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);

        app(EprelEnergySyncService::class)->sync($run);

        $this->assertSame(MsanProduct::EPREL_EXACT, $source->refresh()->eprel_match_status);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'eprel_registration_number' => '1234567',
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
        Http::assertSent(static fn (Request $request): bool => str_contains(
            $request->url(),
            'supplierOrTrademark=ACME',
        ));
    }

    public function test_background_sync_skips_conflicting_model_and_part_number_matches(): void
    {
        $product = Product::query()->create([
            'code' => 'BACKGROUND-CONFLICT',
            'sku' => 'BACKGROUND-CONFLICT',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
        ]);
        $category = MsanCategory::query()->create([
            'external_id' => 'BACKGROUND-CONFLICT-CATEGORY',
            'name' => 'Konfliktni modeli',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::GROUP,
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
        ]);
        $source = MsanProduct::query()->create([
            'external_code' => 'BACKGROUND-CONFLICT-SOURCE',
            'name' => 'ACME konflikt',
            'brand' => 'ACME',
            'model' => 'MODEL-A',
            'part_number' => 'MODEL-B',
            'selected' => true,
            'is_stale' => false,
            'local_product_id' => $product->id,
            'import_status' => MsanProduct::IMPORT_PENDING,
            'last_seen_at' => now(),
        ]);
        $source->categories()->attach($category->id, ['last_seen_at' => now()]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/1111111')) {
                return Http::response([
                    'eprelRegistrationNumber' => '1111111',
                    'productGroup' => self::GROUP_CODE,
                    'modelIdentifier' => 'MODEL-A',
                    'supplierOrTrademark' => 'ACME',
                ]);
            }
            if (str_contains($request->url(), '/2222222')) {
                return Http::response([
                    'eprelRegistrationNumber' => '2222222',
                    'productGroup' => self::GROUP_CODE,
                    'modelIdentifier' => 'MODEL-B',
                    'supplierOrTrademark' => 'ACME',
                ]);
            }
            $model = str_contains($request->url(), 'MODEL-B') ? 'MODEL-B' : 'MODEL-A';
            $registration = $model === 'MODEL-B' ? '2222222' : '1111111';

            return Http::response(['size' => 1, 'hits' => [[
                'eprelRegistrationNumber' => $registration,
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => $model,
                'supplierOrTrademark' => 'ACME',
            ]]]);
        });
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);

        app(EprelEnergySyncService::class)->sync($run);

        $this->assertSame(MsanProduct::EPREL_INVALID, $source->refresh()->eprel_match_status);
        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
        $run->refresh();
        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame(1, $run->summary['invalid_local_data']);
    }

    public function test_no_match_attempt_is_recorded_and_not_retried_until_it_is_stale(): void
    {
        $category = MsanCategory::query()->create([
            'external_id' => 'EPREL-NO-MATCH-CATEGORY',
            'name' => 'Hladnjaci bez rezultata',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::GROUP,
            'energy_requirement' => 'required',
        ]);
        [$product, $source] = $this->linkedSource('EPREL-NO-MATCH', '9999999', true, MsanProduct::IMPORT_PENDING);
        $source->categories()->attach($category->id, ['last_seen_at' => now()]);
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'msan-fallback',
            'energy_class' => 'D',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'energy_label_url' => 'https://supplier.example.test/label.pdf',
            'is_primary' => false,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
        ]);
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'eprel-stale',
            'energy_class' => 'B',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'eprel_registration_number' => '9999999',
            'energy_label_url' => EprelClient::BASE_URL.'/old-label.pdf',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
        $product->forceFill([
            'energy_label_required' => true,
            'energy_efficiency_class' => 'B',
            'energy_efficiency_scale' => 'A-G',
            'eprel_registration_number' => '9999999',
            'energy_label_url' => EprelClient::BASE_URL.'/old-label.pdf',
        ])->save();

        Http::fake(fn () => Http::response([], 404));
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        app(EprelEnergySyncService::class)->sync($run);

        $source->refresh();
        $this->assertSame(MsanProduct::EPREL_NO_MATCH, $source->eprel_match_status);
        $this->assertNotNull($source->eprel_checked_at);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $source->eprel_identifier_checksum);
        $this->assertSame(1, $run->refresh()->summary['not_matched']);
        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
            'is_primary' => true,
        ]);
        $this->assertSame('D', $product->refresh()->energy_efficiency_class);
        $this->assertSame('https://supplier.example.test/label.pdf', $product->energy_label_url);

        Http::fake();
        $secondRun = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        app(EprelEnergySyncService::class)->sync($secondRun);
        $this->assertSame(0, $secondRun->refresh()->total_count);
        Http::assertNothingSent();
    }

    public function test_batch_size_scales_down_to_fit_the_configured_http_timeout(): void
    {
        app(MsanSettingsService::class)->saveAdminValues(['msan_eprel_timeout' => 120]);
        $category = MsanCategory::query()->create([
            'external_id' => 'EPREL-SLOW-CATEGORY',
            'name' => 'Spori EPREL test',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::GROUP,
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
        ]);
        foreach (['1111111', '2222222'] as $registration) {
            [, $source] = $this->linkedSource(
                'EPREL-SLOW-'.$registration,
                $registration,
                true,
                MsanProduct::IMPORT_PENDING,
            );
            $source->categories()->attach($category->id, ['last_seen_at' => now()]);
        }
        Http::fake(function (Request $request) {
            preg_match('#/([0-9]+)$#', $request->url(), $matches);
            $registration = $matches[1] ?? '1111111';

            return Http::response([
                'eprelRegistrationNumber' => $registration,
                'productGroup' => self::GROUP_CODE,
                'modelIdentifier' => 'MODEL-'.$registration,
                'energyClass' => 'C',
            ]);
        });
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);

        app(EprelEnergySyncService::class)->sync($run);

        $run->refresh();
        $this->assertSame(1, $run->total_count);
        $this->assertSame(1, $run->summary['run_limit']);
        $this->assertSame(1, $run->summary['deferred_products']);
        Http::assertSentCount(1);
    }

    public function test_coordinator_queues_eprel_as_an_isolated_integrations_job_without_a_secret_in_the_run(): void
    {
        Queue::fake();
        $category = MsanCategory::query()->create([
            'external_id' => 'EPREL-QUEUE-CATEGORY',
            'name' => 'Hladnjaci za red',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::GROUP,
            'energy_requirement' => 'required',
        ]);
        [, $source] = $this->linkedSource('EPREL-QUEUE', '1010101', true, MsanProduct::IMPORT_PENDING);
        $source->categories()->attach($category->id, ['last_seen_at' => now()]);

        $run = app(MsanCatalogSyncCoordinator::class)->queueEprelEnergy();

        $this->assertSame(MsanSyncRun::KIND_EPREL, $run->kind);
        $this->assertSame(MsanSyncRun::STATUS_PENDING, $run->status);
        $this->assertStringNotContainsString('synthetic-eprel-key', json_encode($run->toArray(), JSON_THROW_ON_ERROR));
        Queue::assertPushed(
            SyncEprelEnergyJob::class,
            fn (SyncEprelEnergyJob $job): bool => $job->queue === 'integrations',
        );
    }

    public function test_job_failure_redacts_an_api_key_from_the_persisted_error(): void
    {
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_EPREL,
            'status' => MsanSyncRun::STATUS_RUNNING,
        ]);

        (new SyncEprelEnergyJob((int) $run->id))
            ->failed(new RuntimeException('x-api-key=must-not-be-stored transport failed'));

        $run->refresh();
        $this->assertSame(MsanSyncRun::STATUS_FAILED, $run->status);
        $this->assertStringNotContainsString('must-not-be-stored', (string) $run->error_message);
        $this->assertStringContainsString('[skriveno]', (string) $run->error_message);
    }

    /** @return array{Product, MsanProduct} */
    private function linkedSource(string $code, string $registration, bool $selected, string $importStatus): array
    {
        $product = Product::query()->create([
            'code' => $code,
            'sku' => $code,
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
            'eprel_registration_number' => $registration,
            'payload' => ['catalog_origin' => 'msan'],
        ]);
        $source = MsanProduct::query()->create([
            'external_code' => $code,
            'name' => 'Test '.$code,
            'model' => 'MODEL-'.$registration,
            'selected' => $selected,
            'is_stale' => false,
            'local_product_id' => $product->id,
            'import_status' => $importStatus,
            'last_seen_at' => now(),
        ]);

        return [$product, $source];
    }
}
