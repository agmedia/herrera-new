<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Services\Integrations\Eprel\EprelSettingsService;
use App\Services\Integrations\Msan\EprelDocumentService;
use App\Services\Integrations\Msan\EprelProductIdentity;
use App\Services\Settings\SystemSettingsService;
use App\Support\ProductEnergyLabelPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EprelDocumentDeliveryFeatureTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-private-eprel-document-key';

    private const LABEL_URL = 'https://eprel.ec.europa.eu/labels/lightsources/Label_646868_big_color.pdf';

    private const SHEET_URL = 'https://eprel.ec.europa.eu/fiches/lightsources/Fiche_646868_HR.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'commerce.b2b_only' => true]);
        Cache::flush();
        Storage::fake('public');
        Storage::fake('local');
        app(EprelSettingsService::class)->saveAdminValues([
            EprelSettingsService::KEY_ENABLED => true,
            EprelSettingsService::KEY_API_KEY => self::KEY,
        ]);
        Http::preventStrayRequests();
    }

    public function test_confirmed_documents_render_direct_official_links_without_downloading_or_saving_pdfs(): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        $beforeProduct = $product->fresh()->getAttributes();
        $beforeDeclaration = $declaration->fresh()->getAttributes();
        $loaded = Product::query()->withStorefrontEnergyData()->findOrFail($product->id);
        $presented = app(ProductEnergyLabelPresenter::class)->primaryDeclaration($loaded);
        $this->assertSame(self::LABEL_URL, $presented['energy_label_url']);
        $this->assertSame(self::SHEET_URL, $presented['product_information_sheet_url']);
        $this->assertTrue($presented['is_complete']);
        $this->assertTrue($presented['has_documents']);
        $this->assertSame(self::LABEL_URL, app(EprelDocumentService::class)->url($loaded, $declaration, 'label'));
        $this->assertSame(self::SHEET_URL, app(EprelDocumentService::class)->url($loaded, $declaration, 'sheet'));
        $html = Blade::render(
            '<x-front.energy-label-arrow :declaration="$declaration" /><x-front.energy-information-sheet-link :declaration="$declaration" />',
            ['declaration' => $presented],
        );
        foreach ([self::LABEL_URL, self::SHEET_URL] as $url) {
            $this->assertStringContainsString('href="'.$url.'"', $html);
        }
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString($this->url($product, $declaration), $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsString('x-api-key', $html);
        $this->get(route('products.show', ['slug' => 'eprel-doc-main']))->assertOk()
            ->assertSee('href="'.self::LABEL_URL.'"', false)->assertSee('href="'.self::SHEET_URL.'"', false)
            ->assertDontSee('href="'.$this->url($product, $declaration).'"', false)->assertDontSee(self::KEY, false);
        $this->get(route('shop.index'))->assertOk()
            ->assertSee('href="'.self::LABEL_URL.'"', false)->assertSee('href="'.self::SHEET_URL.'"', false)
            ->assertDontSee(self::KEY, false);
        $this->assertSame($beforeProduct, $product->fresh()->getAttributes());
        $this->assertSame($beforeDeclaration, $declaration->fresh()->getAttributes());
        $this->assertNoDocumentSideEffects();
    }

    public function test_old_local_routes_only_redirect_to_canonical_public_documents_and_ignore_untrusted_inputs(): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        $query = http_build_query([
            'url' => 'https://evil.example/steal?secret='.self::KEY,
            'redirect' => '//evil.example/steal', 'x-api-key' => self::KEY,
            'language' => 'EN', 'document' => 'https://evil.example/custom.pdf',
        ]);
        foreach (['label' => self::LABEL_URL, 'sheet' => self::SHEET_URL] as $kind => $expected) {
            $response = $this->get($this->url($product, $declaration, $kind).'?'.$query)
                ->assertStatus(302)->assertRedirect($expected)
                ->assertDontSee(self::KEY, false)->assertDontSee('evil.example', false);
            $this->assertSame('https', parse_url($response->headers->get('Location'), PHP_URL_SCHEME));
            $this->assertSame('eprel.ec.europa.eu', parse_url($response->headers->get('Location'), PHP_URL_HOST));
            $this->assertNull(parse_url($response->headers->get('Location'), PHP_URL_QUERY));
            $this->assertNull(parse_url($response->headers->get('Location'), PHP_URL_FRAGMENT));
            $this->assertFalse($response->headers->has('x-api-key'));
            $this->assertFalse($response->headers->has('Content-Disposition'));
            $this->assertNotSame('application/pdf', $response->headers->get('Content-Type'));
        }
        $this->assertNoDocumentSideEffects();
    }

    public function test_documents_require_the_linked_active_product_and_allowlisted_document_kind(): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        [$otherProduct] = $this->linkedDeclaration('other', '654321');
        $documents = app(EprelDocumentService::class);
        $this->assertNull($documents->url($otherProduct, $declaration, 'label'));
        $this->get($this->url($otherProduct, $declaration))->assertNotFound();
        foreach (['arbitrary', 'download', 'LABEL', 'https://evil.example/secret.pdf'] as $kind) {
            $this->assertNull($documents->url($product, $declaration, $kind));
        }
        $this->get($this->url($product, $declaration, 'arbitrary'))->assertNotFound();
        $product->update(['is_active' => false]);
        $this->assertNull($documents->url($product, $declaration, 'label'));
        $this->get($this->url($product, $declaration))->assertNotFound();
        $this->assertNoDocumentSideEffects();
    }

    #[DataProvider('invalidDeclarations')]
    public function test_unconfirmed_or_invalid_declarations_never_create_a_document_link_or_redirect(array $changes): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        $declaration->update($changes);
        $documents = app(EprelDocumentService::class);
        foreach (['label', 'sheet'] as $kind) {
            $this->assertNull($documents->url($product, $declaration, $kind));
            $this->get($this->url($product, $declaration, $kind))->assertNotFound();
        }
        // Explicit manual documents remain manual; the official redirect
        // cannot upgrade them into a confirmed EPREL declaration.
        if ($declaration->source === ProductEnergyDeclaration::SOURCE_EPREL) {
            $product->load('energyDeclarations');
            $presented = app(ProductEnergyLabelPresenter::class)->primaryDeclaration($product);
            $this->assertNull($presented['energy_label_url']);
            $this->assertNull($presented['product_information_sheet_url']);
        }
        $this->assertNoDocumentSideEffects();
    }

    public static function invalidDeclarations(): array
    {
        return [
            'not exact' => [['payload' => ['match' => 'ambiguous']]],
            'missing fingerprint' => [['payload' => ['match' => 'exact']]],
            'invalid fingerprint' => [['payload' => ['match' => 'exact', 'product_identity' => 'v1:not-a-hash']]],
            'manual source' => [['source' => ProductEnergyDeclaration::SOURCE_MANUAL]],
            'supplier source' => [['source' => ProductEnergyDeclaration::SOURCE_MSAN]],
            'legacy source' => [['source' => 'herrera-opencart']],
            'unknown group' => [['eprel_product_group' => 'made-up-group']],
            'traversal group' => [['eprel_product_group' => '../internal']],
            'external group' => [['eprel_product_group' => 'https://evil.example']],
            'encoded group' => [['eprel_product_group' => 'lightsources%2f..']],
            'empty registration' => [['eprel_registration_number' => '']],
            'traversal registration' => [['eprel_registration_number' => '../999']],
            'query registration' => [['eprel_registration_number' => '646868?url=https://evil.example']],
            'linebreak registration' => [['eprel_registration_number' => "646868\nLocation: https://evil.example"]],
            'too long registration' => [['eprel_registration_number' => str_repeat('9', 21)]],
        ];
    }

    #[DataProvider('changedIdentities')]
    public function test_changed_product_identity_hides_official_links_and_rejects_old_local_routes(string $field): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        $this->get($this->url($product, $declaration))->assertRedirect(self::LABEL_URL);
        if ($field === 'manufacturer_id') {
            $manufacturer = Manufacturer::query()->create(['code' => 'OTHER-BRAND', 'is_active' => true]);
            $product->update(['manufacturer_id' => $manufacturer->id]);
        } elseif (str_starts_with($field, 'opencart.')) {
            $payload = $product->payload;
            data_set($payload, $field, 'CHANGED-SOURCE-IDENTIFIER');
            $product->update(['payload' => $payload]);
        } else {
            $product->update([$field => 'CHANGED-NATIVE-IDENTIFIER']);
        }
        $loaded = Product::query()->withStorefrontEnergyData()->findOrFail($product->id);
        $presented = app(ProductEnergyLabelPresenter::class)->primaryDeclaration($loaded);
        $this->assertNull($presented['energy_label_url']);
        $this->assertNull($presented['product_information_sheet_url']);
        foreach (['label', 'sheet'] as $kind) {
            $this->get($this->url($product, $declaration, $kind))->assertNotFound();
        }
        $this->assertNoDocumentSideEffects();
    }

    public static function changedIdentities(): array
    {
        return array_map(static fn ($field) => [$field], ['code', 'sku', 'barcode', 'manufacturer_id', 'opencart.model', 'opencart.ean']);
    }

    public function test_removed_link_is_not_redirected_and_updated_official_identity_uses_the_current_canonical_path(): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        $declaration->update(['eprel_registration_number' => '646999', 'eprel_product_group' => 'tyres']);
        $this->get($this->url($product, $declaration))->assertRedirect('https://eprel.ec.europa.eu/labels/tyres/Label_646999_big_color.pdf');
        $declaration->delete();
        $this->get($this->url($product, $declaration))->assertNotFound();
        $this->assertNoDocumentSideEffects();
    }

    public function test_public_documents_keep_working_when_lookup_is_disabled_or_key_is_removed_or_corrupt(): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        app(EprelSettingsService::class)->saveAdminValues([EprelSettingsService::KEY_ENABLED => false]);
        foreach (['', 'not-valid-encrypted-data'] as $storedKey) {
            app(SystemSettingsService::class)->put(EprelSettingsService::KEY_API_KEY_ENCRYPTED, $storedKey);
            $product->load('energyDeclarations');
            $presented = app(ProductEnergyLabelPresenter::class)->primaryDeclaration($product);
            $this->assertSame(self::LABEL_URL, $presented['energy_label_url']);
            $this->assertSame(self::SHEET_URL, $presented['product_information_sheet_url']);
            foreach (['label' => self::LABEL_URL, 'sheet' => self::SHEET_URL] as $kind => $expected) {
                $this->get($this->url($product, $declaration, $kind))->assertRedirect($expected)
                    ->assertDontSee(self::KEY, false)->assertDontSee('not-valid-encrypted-data', false);
            }
        }
        $this->assertNoDocumentSideEffects();
    }

    public function test_only_saved_linked_models_can_create_official_links(): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        $documents = app(EprelDocumentService::class);
        $this->assertNull($documents->url($product->replicate(), $declaration, 'label'));
        $this->assertNull($documents->url($product, $declaration->replicate(), 'sheet'));
        $this->assertNoDocumentSideEffects();
    }

    public function test_manual_urls_and_imported_grade_only_labels_keep_their_existing_contract(): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        $declaration->update([
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            'energy_label_url' => 'https://cdn.example.test/manual-label.pdf',
            'product_information_sheet_url' => 'https://cdn.example.test/manual-sheet.pdf',
        ]);
        $product->load('energyDeclarations');
        $presenter = app(ProductEnergyLabelPresenter::class);
        $manual = $presenter->primaryDeclaration($product);
        $this->assertSame('https://cdn.example.test/manual-label.pdf', $manual['energy_label_url']);
        $this->assertSame('https://cdn.example.test/manual-sheet.pdf', $manual['product_information_sheet_url']);
        $declaration->update(['source' => 'herrera-opencart', 'energy_label_url' => null, 'product_information_sheet_url' => null]);
        $product->load('energyDeclarations');
        $gradeOnly = $presenter->primaryDeclaration($product);
        $this->assertTrue($gradeOnly['has_arrow']);
        $this->assertFalse($gradeOnly['has_documents']);
        $this->assertNoDocumentSideEffects();
    }

    public function test_legacy_native_projection_preserves_manual_links_without_guessing_official_documents(): void
    {
        [$product, $declaration] = $this->linkedDeclaration();
        $declaration->delete();
        $product->update([
            'energy_efficiency_class' => 'F', 'energy_efficiency_scale' => 'A-G',
            'eprel_registration_number' => '646868', 'eprel_product_group' => 'lightsources',
            'energy_label_url' => 'https://manual.example.test/label.pdf',
            'product_information_sheet_url' => 'https://manual.example.test/sheet.pdf',
        ]);
        $product->load('energyDeclarations');
        $presenter = app(ProductEnergyLabelPresenter::class);
        $manual = $presenter->primaryDeclaration($product);
        $this->assertSame('https://manual.example.test/label.pdf', $manual['energy_label_url']);
        $this->assertSame('https://manual.example.test/sheet.pdf', $manual['product_information_sheet_url']);
        $product->update(['energy_label_url' => null, 'product_information_sheet_url' => null]);
        $unconfirmed = $presenter->primaryDeclaration($product);
        $this->assertTrue($unconfirmed['has_arrow']);
        $this->assertFalse($unconfirmed['has_documents']);
        $this->assertNoDocumentSideEffects();
    }

    private function assertNoDocumentSideEffects(): void
    {
        Http::assertNothingSent();
        $documentEntries = array_filter(Cache::getStore()->all(), static fn ($key): bool => str_starts_with($key, 'eprel-document:'), ARRAY_FILTER_USE_KEY);
        $this->assertSame([], $documentEntries);
        foreach (Cache::getStore()->all() as $entry) {
            $serialized = serialize($entry['value']);
            $this->assertStringNotContainsString('%PDF-', $serialized);
            $this->assertStringNotContainsString('JVBERi0', $serialized);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('media', 0);
    }

    /** @return array{Product,ProductEnergyDeclaration} */
    private function linkedDeclaration(string $suffix = 'main', string $registration = '646868'): array
    {
        $product = Product::query()->create([
            'code' => 'EPREL-DOC-'.$suffix, 'sku' => 'EPREL-DOC-'.$suffix,
            'is_active' => true, 'stock_qty' => 1, 'base_price' => 15,
            'barcode' => 'DOC-BARCODE-'.$suffix,
            'payload' => ['opencart' => ['model' => 'SOURCE-'.$suffix, 'ean' => '9120072372216']],
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'EPREL document product '.$suffix, 'slug' => 'eprel-doc-'.$suffix]);
        $declaration = $product->energyDeclarations()->create([
            'context_code' => 'official', 'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            'energy_class' => 'F', 'scale_min' => 'A', 'scale_max' => 'G', 'is_primary' => true,
            'eprel_product_group' => 'lightsources', 'eprel_registration_number' => $registration,
            'energy_label_url' => 'https://evil.example/untrusted-label.pdf',
            'product_information_sheet_url' => 'https://evil.example/untrusted-sheet.pdf',
            'payload' => ['match' => 'exact', 'product_identity' => EprelProductIdentity::fingerprint($product)], 'synced_at' => now(),
        ]);

        return [$product, $declaration];
    }

    private function url(Product $product, ProductEnergyDeclaration $declaration, string $kind = 'label'): string
    {
        return route('products.energy.document', [
            'product' => $product->id, 'declaration' => $declaration->id, 'document' => $kind,
        ]);
    }
}
