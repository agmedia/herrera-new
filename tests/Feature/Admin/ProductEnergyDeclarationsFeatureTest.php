<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Livewire\Admin\Media\Manager as MediaManager;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\User;
use App\Services\Integrations\Msan\EprelClient;
use App\Services\Integrations\Msan\MsanSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProductEnergyDeclarationsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const GTIN = '9120072372216';

    private const EPREL_GROUP = 'electronicdisplays';

    private const EPREL_GROUP_CODE = 'ELECTRONIC_DISPLAY';

    protected function setUp(): void
    {
        parent::setUp();

        app(MsanSettingsService::class)->saveAdminValues([
            'msan_enabled' => true,
            'msan_eprel_enabled' => true,
            'msan_eprel_api_key' => 'synthetic-product-form-eprel-key',
            'msan_eprel_connect_timeout' => 5,
            'msan_eprel_timeout' => 15,
        ]);
        Http::preventStrayRequests();
    }

    public function test_admin_saves_manual_primary_declaration_without_overwriting_imported_rows(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $imported = ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'msan-sdr',
            'label' => 'SDR',
            'energy_class' => 'D',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'eprel_registration_number' => '1713217',
            'eprel_product_group' => 'electronicdisplays',
            'energy_label_image' => 'D A-G.svg',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertSet('activeTab', 'energy')
            ->call('addEnergyDeclaration')
            ->set('form.energy_label_required', true)
            ->set('energyDeclarations.1.context_code', 'manual-hdr')
            ->set('energyDeclarations.1.label', 'HDR')
            ->set('energyDeclarations.1.energy_class', 'E')
            ->set('energyDeclarations.1.scale_min', 'A')
            ->set('energyDeclarations.1.scale_max', 'G')
            ->set('energyDeclarations.1.energy_label_url', 'https://cdn.example.test/labels/hdr.pdf')
            ->set('energyDeclarations.1.product_information_sheet_url', 'https://cdn.example.test/fiches/hdr.pdf')
            ->call('setPrimaryEnergyDeclaration', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_energy_declarations', [
            'id' => $imported->id,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
            'energy_class' => 'D',
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'context_code' => 'manual-hdr',
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            'energy_class' => 'E',
            'is_primary' => true,
        ]);

        $product->refresh();
        $this->assertTrue($product->energy_label_required);
        $this->assertSame('E', $product->energy_efficiency_class);
        $this->assertSame('A-G', $product->energy_efficiency_scale);
    }

    public function test_pdf_information_sheet_is_never_promoted_or_edited_as_main_image(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $component = Livewire::actingAs($user)
            ->test(MediaManager::class, [
                'modelClass' => Product::class,
                'modelId' => $product->id,
                'locale' => 'hr',
            ])
            ->set('uploads.product_information_sheet', UploadedFile::fake()->createWithContent(
                'informacijski-list.pdf',
                "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",
            ))
            ->call('uploadCollection', 'product_information_sheet')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $product->refresh();
        $document = $product->getFirstMedia('product_information_sheet');
        $this->assertNotNull($document);
        $this->assertNull($product->getFirstMedia('product_main'));

        $component
            ->assertSee('Open document')
            ->assertDontSee('data-image-edit-open', false)
            ->call('copyToMain', $document->id)
            ->assertDispatched('notify');

        $this->assertNull($product->fresh()->getFirstMedia('product_main'));
    }

    public function test_required_energy_documentation_warning_uses_only_valid_assets(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $product->forceFill(['energy_label_required' => true])->save();
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'unsafe-import',
            'energy_class' => 'A',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'energy_label_image' => '../A.svg?token=secret',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertSee('data-energy-compliance-warning', false)
            ->assertSee('Nedostaju službena energetska oznaka i informacijski list proizvoda (PIS).');

        $product->energyDeclarations()->update([
            'energy_label_image' => 'A A-G.svg',
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertSee('data-energy-compliance-warning', false)
            ->assertSee('Nedostaju službena energetska oznaka i informacijski list proizvoda (PIS).');

        $product->energyDeclarations()->update([
            'eprel_registration_number' => '1713217',
            'eprel_product_group' => 'electronicdisplays',
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertSee('data-energy-compliance-warning', false);

        $product->energyDeclarations()->update([
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertDontSee('data-energy-compliance-warning', false);
    }

    public function test_admin_rejects_an_energy_class_outside_the_selected_scale(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('addEnergyDeclaration')
            ->set('energyDeclarations.0.context_code', 'invalid-range')
            ->set('energyDeclarations.0.energy_class', 'B')
            ->set('energyDeclarations.0.scale_min', 'C')
            ->set('energyDeclarations.0.scale_max', 'G')
            ->set('energyDeclarations.0.energy_label_url', 'https://cdn.example.test/labels/product.pdf')
            ->call('save')
            ->assertHasErrors(['energyDeclarations.0.energy_class']);

        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $product->id,
            'context_code' => 'invalid-range',
        ]);
    }

    public function test_manual_eprel_identity_change_invalidates_the_cached_supplier_match(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $product->forceFill([
            'eprel_registration_number' => '1111111',
            'eprel_product_group' => 'electronicdisplays',
        ])->save();
        $source = MsanProduct::query()->create([
            'external_code' => 'ENERGY-ADMIN-SOURCE',
            'name' => 'Energetski artikl',
            'local_product_id' => $product->id,
            'eprel_match_status' => MsanProduct::EPREL_EXACT,
            'eprel_identifier_checksum' => str_repeat('b', 64),
            'eprel_checked_at' => now(),
            'is_stale' => false,
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('addEnergyDeclaration')
            ->set('energyDeclarations.0.context_code', 'manual-new-eprel')
            ->set('energyDeclarations.0.energy_class', 'C')
            ->set('energyDeclarations.0.scale_min', 'A')
            ->set('energyDeclarations.0.scale_max', 'G')
            ->set('energyDeclarations.0.eprel_registration_number', '2222222')
            ->set('energyDeclarations.0.eprel_product_group', 'refrigeratingappliances2019')
            ->set('energyDeclarations.0.is_primary', true)
            ->call('save')
            ->assertHasNoErrors();

        $source->refresh();
        $this->assertSame(MsanProduct::EPREL_PENDING, $source->eprel_match_status);
        $this->assertNull($source->eprel_identifier_checksum);
        $this->assertNull($source->eprel_checked_at);
    }

    public function test_local_product_without_msan_link_can_lookup_and_store_an_exact_gtin_declaration(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $product->forceFill(['barcode' => self::GTIN])->save();
        Http::fake([
            EprelClient::BASE_URL.'/api/product/gtin/'.self::GTIN => Http::response([
                'hits' => [[
                    'eprelRegistrationNumber' => '646868',
                    'productGroup' => self::EPREL_GROUP_CODE,
                    'modelIdentifier' => 'M27FC12401',
                    'supplierOrTrademark' => 'THOMSON',
                    'gtinIdentifier' => self::GTIN,
                    'energyClass' => 'F',
                    'scaleMin' => 'A',
                    'scaleMax' => 'G',
                    'energyClassImageWithScale' => 'F-Left-Orange-WithAGScale.svg',
                    'productInformationSheetUrl' => '/api/products/'.self::EPREL_GROUP.'/646868/product-information-sheet?format=PDF',
                ]],
            ]),
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertSee('data-eprel-lookup', false)
            ->assertSee('data-eprel-lookup-criteria', false)
            ->assertSee('Automatski dohvati iz EPREL-a')
            ->assertSee('Pretražujem EPREL…')
            ->assertSee('Barkod')
            ->assertSee(self::GTIN)
            ->call('lookupEprel')
            ->assertHasNoErrors('eprelLookup')
            ->assertDispatched('notify', type: 'success')
            ->assertDontSee('data-eprel-lookup-error', false)
            ->assertSet('energyDeclarations.0.source', ProductEnergyDeclaration::SOURCE_EPREL)
            ->assertSet('energyDeclarations.0.energy_class', 'F')
            ->assertSee('Službena EPREL energetska oznaka')
            ->assertSee('EPREL broj');

        $labelUrl = EprelClient::BASE_URL.'/api/products/'.self::EPREL_GROUP.'/646868/labels?format=PDF';
        $sheetUrl = EprelClient::BASE_URL.'/fiches/'.self::EPREL_GROUP.'/Fiche_646868_HR.pdf';
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'eprel_registration_number' => '646868',
            'eprel_product_group' => self::EPREL_GROUP,
            'energy_class' => 'F',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'energy_label_url' => $labelUrl,
            'product_information_sheet_url' => $sheetUrl,
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
        $product->refresh();
        $this->assertTrue($product->energy_label_required);
        $this->assertSame('F', $product->energy_efficiency_class);
        $this->assertSame($labelUrl, $product->energy_label_url);
        $this->assertSame($sheetUrl, $product->product_information_sheet_url);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url()
            === EprelClient::BASE_URL.'/api/product/gtin/'.self::GTIN
            && $request->header('x-api-key') === ['synthetic-product-form-eprel-key']);
    }

    public function test_explicit_lookup_group_action_persists_and_reloads_without_changing_existing_declaration_identity(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $declaration = ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'existing-eprel',
            'eprel_registration_number' => '646868',
            'eprel_product_group' => self::EPREL_GROUP,
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
        $product->forceFill([
            'eprel_registration_number' => '646868',
            'eprel_product_group' => self::EPREL_GROUP,
        ])->save();

        $component = Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertSee('data-native-select data-eprel-lookup-group', false);

        foreach (['eprel-lookup-search-by', 'eprel-lookup-group'] as $selectId) {
            $this->assertMatchesRegularExpression(
                '/<select\b(?=[^>]*\bid="'.preg_quote($selectId, '/').'"\s)(?=[^>]*\bdata-native-select\b)(?![^>]*\bclass="[^"]*\badmin-select\b)[^>]*>/u',
                $component->html(),
            );
        }

        $component
            ->call('saveEprelLookupGroup', 'lightsources')
            ->assertSet('eprelLookupGroup', 'lightsources')
            ->assertHasNoErrors('eprelLookup')
            ->assertDispatched('notify', type: 'success');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'eprel_lookup_product_group' => 'lightsources',
            'eprel_product_group' => self::EPREL_GROUP,
            'eprel_registration_number' => '646868',
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'id' => $declaration->id,
            'eprel_product_group' => self::EPREL_GROUP,
            'eprel_registration_number' => '646868',
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->assertSet('eprelLookupGroup', 'lightsources')
            ->call('saveEprelLookupGroup', '')
            ->assertSet('eprelLookupGroup', '')
            ->assertHasNoErrors('eprelLookup')
            ->assertDispatched('notify', type: 'success');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'eprel_lookup_product_group' => null,
            'eprel_product_group' => self::EPREL_GROUP,
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'id' => $declaration->id,
            'eprel_product_group' => self::EPREL_GROUP,
        ]);
    }

    public function test_admin_can_lookup_an_eprel_registration_number_without_selecting_a_group(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        Http::fake([
            EprelClient::BASE_URL.'/api/product/2210952' => Http::response([
                'eprelRegistrationNumber' => '2210952',
                'productGroup' => self::EPREL_GROUP_CODE,
                'modelIdentifier' => 'DD-235E S',
                'supplierOrTrademark' => 'VIVAX',
                'energyClass' => 'E',
            ]),
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertSee('EPREL registracijski broj')
            ->assertSet('eprelLookupGroup', '')
            ->set('eprelLookupSearchBy', EprelClient::SEARCH_REGISTRATION_NUMBER)
            ->set('eprelLookupQuery', '2210952')
            ->call('lookupEprel')
            ->assertHasNoErrors('eprelLookup')
            ->assertSet('eprelLookupGroup', self::EPREL_GROUP)
            ->assertSet('energyDeclarations.0.source', ProductEnergyDeclaration::SOURCE_EPREL)
            ->assertSet('energyDeclarations.0.eprel_registration_number', '2210952')
            ->assertSet('energyDeclarations.0.energy_class', 'E')
            ->assertDispatched('notify', type: 'success');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'eprel_lookup_product_group' => self::EPREL_GROUP,
            'eprel_registration_number' => '2210952',
            'eprel_product_group' => self::EPREL_GROUP,
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            'eprel_registration_number' => '2210952',
            'eprel_product_group' => self::EPREL_GROUP,
            'energy_class' => 'E',
        ]);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url()
            === EprelClient::BASE_URL.'/api/product/2210952');

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->assertSet('eprelLookupGroup', self::EPREL_GROUP);
    }

    public function test_manual_commercial_name_search_uses_the_official_mode_and_stores_one_exact_result(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        Http::fake(function (Request $request) {
            if ($request->url() === EprelClient::BASE_URL.'/api/products/'.self::EPREL_GROUP.'/646868') {
                return Http::response([
                    'eprelRegistrationNumber' => '646868',
                    'productGroup' => self::EPREL_GROUP_CODE,
                    'modelIdentifier' => 'M27FC12401',
                    'supplierOrTrademark' => 'THOMSON',
                    'commercialName' => 'Thomson Easy TV',
                    'energyClass' => 'F',
                ]);
            }

            return Http::response(['size' => 1, 'hits' => [[
                'eprelRegistrationNumber' => '646868',
                'productGroup' => self::EPREL_GROUP_CODE,
                'modelIdentifier' => 'M27FC12401',
                'supplierOrTrademark' => 'THOMSON',
                'additionalDetails' => ['commercialName' => '  THOMSON   EASY TV '],
            ]]]);
        });

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertSee('Identifikator modela')
            ->assertSee('Marka ili zaštitni znak')
            ->assertSee('Komercijalni naziv modela')
            ->assertSee('GTIN identifikator')
            ->assertSee('data-eprel-search-by', false)
            ->assertSee('data-eprel-search-query', false)
            ->set('eprelLookupGroup', self::EPREL_GROUP)
            ->set('eprelLookupSearchBy', EprelClient::SEARCH_COMMERCIAL_NAME)
            ->set('eprelLookupQuery', 'Thomson Easy TV')
            ->call('lookupEprel')
            ->assertHasNoErrors('eprelLookup')
            ->assertDispatched('notify', type: 'success')
            ->assertSet('energyDeclarations.0.source', ProductEnergyDeclaration::SOURCE_EPREL)
            ->assertSet('energyDeclarations.0.energy_class', 'F');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'eprel_lookup_product_group' => self::EPREL_GROUP,
            'eprel_product_group' => self::EPREL_GROUP,
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'eprel_registration_number' => '646868',
            'eprel_product_group' => self::EPREL_GROUP,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => str_starts_with(
            $request->url(),
            EprelClient::BASE_URL.'/api/products/'.self::EPREL_GROUP.'?',
        )
            && str_contains($request->url(), '_page=1')
            && str_contains($request->url(), 'genericField=COMMERCIAL_NAME')
            && str_contains($request->url(), 'commercialName=Thomson%20Easy%20TV')
            && str_contains($request->url(), 'applianceType=ANY')
            && ! str_contains($request->url(), '_page=0'));
    }

    public function test_lookup_rechecks_the_persisted_group_before_storing_a_remote_match(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        Http::fake(function (Request $request) use ($product) {
            if (str_contains($request->url(), 'genericField=COMMERCIAL_NAME')) {
                Product::query()->whereKey($product->id)->update([
                    'eprel_lookup_product_group' => 'lightsources',
                ]);

                return Http::response(['size' => 1, 'hits' => [[
                    'eprelRegistrationNumber' => '646868',
                    'productGroup' => self::EPREL_GROUP_CODE,
                    'commercialName' => 'Thomson Easy TV',
                ]]]);
            }

            return Http::response([
                'eprelRegistrationNumber' => '646868',
                'productGroup' => self::EPREL_GROUP_CODE,
                'commercialName' => 'Thomson Easy TV',
                'energyClass' => 'F',
            ]);
        });

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->set('eprelLookupGroup', self::EPREL_GROUP)
            ->set('eprelLookupSearchBy', EprelClient::SEARCH_COMMERCIAL_NAME)
            ->set('eprelLookupQuery', 'Thomson Easy TV')
            ->call('lookupEprel')
            ->assertHasErrors('eprelLookup')
            ->assertSee('Odabrana EPREL grupa promijenjena je tijekom dohvata.');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'eprel_lookup_product_group' => 'lightsources',
        ]);
        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
    }

    public function test_linked_msan_product_falls_back_to_exact_brand_and_model_lookup_in_the_mapped_group(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $category = MsanCategory::query()->create([
            'external_id' => 'EPREL-DISPLAYS',
            'name' => 'Zasloni',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::EPREL_GROUP,
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
        ]);
        $source = MsanProduct::query()->create([
            'external_code' => 'MSAN-MODEL-LOOKUP',
            'name' => 'THOMSON monitor',
            'brand' => 'THOMSON',
            'model' => 'M27FC12401',
            'selected' => true,
            'is_stale' => false,
            'local_product_id' => $product->id,
            'import_status' => MsanProduct::IMPORT_IMPORTED,
            'last_seen_at' => now(),
        ]);
        $source->categories()->attach($category->id, ['last_seen_at' => now()]);

        Http::fake(function (Request $request) {
            if ($request->url() === EprelClient::BASE_URL.'/api/products/'.self::EPREL_GROUP.'/646868') {
                return Http::response([
                    'eprelRegistrationNumber' => '646868',
                    'productGroup' => self::EPREL_GROUP_CODE,
                    'modelIdentifier' => 'M27FC12401',
                    'supplierOrTrademark' => 'THOMSON',
                    'energyClass' => 'E',
                    'energyClassRange' => 'A-G',
                ]);
            }

            if (str_starts_with(
                $request->url(),
                EprelClient::BASE_URL.'/api/products/'.self::EPREL_GROUP.'?',
            )) {
                return Http::response([
                    'hits' => [[
                        'eprelRegistrationNumber' => '646868',
                        'productGroup' => self::EPREL_GROUP_CODE,
                        'modelIdentifier' => 'M27FC12401',
                        'supplierOrTrademark' => 'THOMSON',
                    ]],
                ]);
            }

            return Http::response([], 404);
        });

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->assertSee('M27FC12401')
            ->assertSee('THOMSON')
            ->assertSee('Automatski prepoznato: '.self::EPREL_GROUP)
            ->call('lookupEprel')
            ->assertHasNoErrors('eprelLookup')
            ->assertDispatched('notify', type: 'success')
            ->assertSet('energyDeclarations.0.source', ProductEnergyDeclaration::SOURCE_EPREL)
            ->assertSet('energyDeclarations.0.energy_class', 'E');

        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            'eprel_registration_number' => '646868',
            'eprel_product_group' => self::EPREL_GROUP,
            'energy_class' => 'E',
            'is_primary' => true,
        ]);
        Http::assertSentCount(4);
        Http::assertSent(static fn (Request $request): bool => str_starts_with(
            $request->url(),
            EprelClient::BASE_URL.'/api/products/'.self::EPREL_GROUP.'?',
        )
            && str_contains($request->url(), 'modelIdentifier=M27FC12401')
            && str_contains($request->url(), 'supplierOrTrademark=THOMSON'));
    }

    public function test_gtin_not_found_without_a_group_shows_a_concrete_lookup_error_and_stores_nothing(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $product->forceFill(['barcode' => self::GTIN])->save();
        Http::fake([
            EprelClient::BASE_URL.'/api/product/gtin/'.self::GTIN => Http::response([], 404),
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->call('lookupEprel')
            ->assertHasErrors('eprelLookup')
            ->assertDispatched('notify', type: 'warning')
            ->assertSee('data-eprel-lookup-error', false)
            ->assertSee('Automatska detekcija grupe nije uspjela. Odaberite EPREL grupu proizvoda; odabir će se odmah spremiti uz artikl.');

        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $product->id,
        ]);
        Http::assertSentCount(1);
    }

    public function test_model_lookup_requires_a_brand_when_none_can_be_detected(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        Http::fake();

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->set('eprelLookupModel', 'MODEL-WITHOUT-BRAND')
            ->set('eprelLookupGroup', self::EPREL_GROUP)
            ->call('lookupEprel')
            ->assertHasErrors('eprelLookup')
            ->assertSee('Za sigurnu pretragu po modelu potrebna je marka.');

        Http::assertNothingSent();
    }

    public function test_model_lookup_rejects_conflicting_exact_source_identifiers_without_storing_either(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $category = MsanCategory::query()->create([
            'external_id' => 'EPREL-CONFLICT-DISPLAYS',
            'name' => 'Zasloni s konfliktom',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $category->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => self::EPREL_GROUP,
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
        ]);
        $source = MsanProduct::query()->create([
            'external_code' => 'MSAN-CONFLICTING-MODELS',
            'name' => 'THOMSON konflikt',
            'brand' => 'THOMSON',
            'model' => 'MODEL-A',
            'part_number' => 'MODEL-B',
            'selected' => true,
            'is_stale' => false,
            'local_product_id' => $product->id,
            'import_status' => MsanProduct::IMPORT_IMPORTED,
            'last_seen_at' => now(),
        ]);
        $source->categories()->attach($category->id, ['last_seen_at' => now()]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/1111111')) {
                return Http::response([
                    'eprelRegistrationNumber' => '1111111',
                    'productGroup' => self::EPREL_GROUP_CODE,
                    'modelIdentifier' => 'MODEL-A',
                    'supplierOrTrademark' => 'THOMSON',
                ]);
            }
            if (str_contains($request->url(), '/2222222')) {
                return Http::response([
                    'eprelRegistrationNumber' => '2222222',
                    'productGroup' => self::EPREL_GROUP_CODE,
                    'modelIdentifier' => 'MODEL-B',
                    'supplierOrTrademark' => 'THOMSON',
                ]);
            }
            if (str_contains($request->url(), 'modelIdentifier=MODEL-A')) {
                return Http::response(['size' => 1, 'hits' => [[
                    'eprelRegistrationNumber' => '1111111',
                    'productGroup' => self::EPREL_GROUP_CODE,
                    'modelIdentifier' => 'MODEL-A',
                    'supplierOrTrademark' => 'THOMSON',
                ]]]);
            }
            if (str_contains($request->url(), 'modelIdentifier=MODEL-B')) {
                return Http::response(['size' => 1, 'hits' => [[
                    'eprelRegistrationNumber' => '2222222',
                    'productGroup' => self::EPREL_GROUP_CODE,
                    'modelIdentifier' => 'MODEL-B',
                    'supplierOrTrademark' => 'THOMSON',
                ]]]);
            }

            return Http::response([], 404);
        });

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->call('lookupEprel')
            ->assertHasErrors('eprelLookup')
            ->assertSee('Različiti modeli ili marke upućuju na više službenih EPREL zapisa.');

        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
    }

    public function test_exact_gtin_lookup_never_replaces_an_existing_manual_primary_declaration(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $product->forceFill([
            'barcode' => self::GTIN,
            'energy_label_required' => true,
            'energy_efficiency_class' => 'D',
            'energy_efficiency_scale' => 'A-G',
            'energy_label_url' => 'https://manual.example.test/label.pdf',
            'product_information_sheet_url' => 'https://manual.example.test/sheet.pdf',
        ])->save();
        $manual = ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'manual-primary',
            'label' => 'Ručna primarna deklaracija',
            'energy_class' => 'D',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'energy_label_url' => 'https://manual.example.test/label.pdf',
            'product_information_sheet_url' => 'https://manual.example.test/sheet.pdf',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);
        Http::fake([
            EprelClient::BASE_URL.'/api/product/gtin/'.self::GTIN => Http::response([
                'hits' => [[
                    'eprelRegistrationNumber' => '646868',
                    'productGroup' => self::EPREL_GROUP_CODE,
                    'modelIdentifier' => 'M27FC12401',
                    'gtinIdentifier' => self::GTIN,
                    'energyClass' => 'F',
                    'energyClassRange' => 'A-G',
                ]],
            ]),
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->call('lookupEprel')
            ->assertHasNoErrors('eprelLookup')
            ->assertDispatched('notify', type: 'success')
            ->assertSet('energyDeclarations.0.id', $manual->id)
            ->assertSet('energyDeclarations.0.source', ProductEnergyDeclaration::SOURCE_MANUAL)
            ->assertSet('energyDeclarations.0.is_primary', true)
            ->assertSet('energyDeclarations.1.source', ProductEnergyDeclaration::SOURCE_EPREL)
            ->assertSet('energyDeclarations.1.is_primary', false);

        $this->assertDatabaseHas('product_energy_declarations', [
            'id' => $manual->id,
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'eprel_registration_number' => '646868',
            'is_primary' => false,
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
        ]);
        $product->refresh();
        $this->assertSame('D', $product->energy_efficiency_class);
        $this->assertSame('https://manual.example.test/label.pdf', $product->energy_label_url);
        $this->assertSame('https://manual.example.test/sheet.pdf', $product->product_information_sheet_url);
    }

    public function test_lookup_requires_manual_declaration_changes_to_be_saved_before_immediate_import(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $product->forceFill(['barcode' => self::GTIN])->save();
        Http::fake([
            EprelClient::BASE_URL.'/api/product/gtin/'.self::GTIN => Http::response([
                'eprelRegistrationNumber' => '646868',
                'productGroup' => self::EPREL_GROUP_CODE,
                'modelIdentifier' => 'M27FC12401',
                'gtinIdentifier' => self::GTIN,
                'energyClass' => 'F',
                'energyClassRange' => 'A-G',
            ]),
        ]);

        $component = Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->call('addEnergyDeclaration')
            ->set('energyDeclarations.0.context_code', 'manual-unsaved')
            ->set('energyDeclarations.0.label', 'Nespremljena ručna deklaracija')
            ->set('energyDeclarations.0.energy_class', 'D')
            ->set('energyDeclarations.0.scale_min', 'A')
            ->set('energyDeclarations.0.scale_max', 'G')
            ->call('setPrimaryEnergyDeclaration', 0)
            ->call('lookupEprel')
            ->assertHasErrors('eprelLookup')
            ->assertSee('Prije EPREL dohvata spremite ili odbacite promjene u energetskim deklaracijama.');

        Http::assertNothingSent();

        $component->call('save');

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->call('lookupEprel')
            ->assertHasNoErrors('eprelLookup')
            ->assertSet('energyDeclarations.0.context_code', 'manual-unsaved')
            ->assertSet('energyDeclarations.0.label', 'Nespremljena ručna deklaracija')
            ->assertSet('energyDeclarations.0.is_primary', true)
            ->assertSet('energyDeclarations.1.source', ProductEnergyDeclaration::SOURCE_EPREL)
            ->assertSet('energyDeclarations.1.is_primary', false);

        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'context_code' => 'manual-unsaved',
            'energy_class' => 'D',
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'eprel_registration_number' => '646868',
            'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            'is_primary' => false,
        ]);
    }

    public function test_lookup_refuses_to_persist_against_an_unsaved_product_identity(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        Http::fake();

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'energy')
            ->set('form.barcode', self::GTIN)
            ->call('lookupEprel')
            ->assertHasErrors('eprelLookup')
            ->assertSee('Prije EPREL dohvata spremite promjene ovih podataka: barkod.');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('product_energy_declarations', [
            'product_id' => $product->id,
        ]);
    }

    private function product(): Product
    {
        $product = Product::query()->create([
            'code' => 'ENERGY-ADMIN-'.str()->random(8),
            'sku' => 'ENERGY-ADMIN-'.str()->random(8),
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 5,
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => 'Energetski artikl',
            'slug' => 'energetski-artikl-'.$product->id,
        ]);

        return $product;
    }
}
