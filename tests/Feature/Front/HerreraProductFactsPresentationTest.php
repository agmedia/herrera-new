<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\CatalogProductSpecification;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use App\Support\ProductEnergyLabelPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraProductFactsPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
    }

    public function test_imported_model_and_ean_and_two_private_stock_sources_are_visible_on_cards_and_detail(): void
    {
        $product = $this->product();
        $this->actingAs($this->approvedBuyer());
        foreach ([route('shop.index'), route('products.show', ['slug' => 'facts-product'])] as $url) {
            $html = $this->get($url)->assertOk()->assertSee('Šifra')->assertSee('BT59-00231')
                ->assertSee('EAN')->assertSee('3850123456789')->assertSee('Dostupnost 48 sati')->assertSee('Dostupnost 14 dana')
                ->getContent();
            $this->assertSame('35.791', $this->textAt($html, 'data-product-local-stock'));
            $this->assertSame('98.237', $this->textAt($html, 'data-product-supplier-stock'));
        }
        $this->assertSame('INTERNAL-SKU', $product->fresh()->sku);
    }

    #[DataProvider('deniedStates')]
    public function test_stock_numbers_and_private_availability_markers_never_reach_unapproved_html(string $state): void
    {
        $this->product();
        if ($state !== 'guest') {
            $buyer = $this->approvedBuyer();
            $account = $buyer->b2bAccount;
            match ($state) {
                'no_account' => $account->delete(),
                'pending', 'suspended' => $account->update(['status' => $state]),
                'inactive_primary_group' => $account->customerGroup->update(['is_active' => false]),
            };
            $this->actingAs($buyer->fresh());
        }

        foreach ([route('shop.index'), route('products.show', ['slug' => 'facts-product'])] as $url) {
            $this->get($url)->assertOk()->assertSee('BT59-00231')->assertSee('3850123456789')
                ->assertDontSee('data-product-availability', false)->assertDontSee('data-product-local-stock', false)
                ->assertDontSee('data-product-supplier-stock', false)->assertDontSee('Dostupnost 48 sati')
                ->assertDontSee('Dostupnost 14 dana')->assertDontSee('35.791')->assertDontSee('98.237');
        }
    }

    public function test_imported_ean_fallback_is_escaped_and_native_barcode_takes_precedence(): void
    {
        $product = $this->product();
        $product->update(['barcode' => '3850000000001']);
        $this->get(route('products.show', ['slug' => 'facts-product']))->assertOk()
            ->assertSee('data-product-ean>3850000000001', false);
        $product->update(['barcode' => null, 'payload' => ['opencart' => ['model' => '<script>bad-code</script>', 'ean' => '<script>bad-ean</script>']]]);
        foreach ([route('shop.index'), route('products.show', ['slug' => 'facts-product'])] as $url) {
            $this->get($url)->assertOk()->assertSee('&lt;script&gt;bad-code&lt;/script&gt;', false)
                ->assertSee('&lt;script&gt;bad-ean&lt;/script&gt;', false)
                ->assertDontSee('<script>bad-code</script>', false)->assertDontSee('<script>bad-ean</script>', false);
        }
    }

    public function test_native_products_use_native_sku_and_omit_empty_ean(): void
    {
        $product = $this->product();
        $product->update(['payload' => null]);
        foreach ([route('shop.index'), route('products.show', ['slug' => 'facts-product'])] as $url) {
            $this->get($url)->assertOk()->assertSee('INTERNAL-SKU')->assertDontSee('data-product-ean', false);
        }
    }

    public function test_supplier_only_product_shows_both_sources_and_a_purchase_control_for_an_approved_buyer(): void
    {
        $product = $this->product();
        $product->update(['stock_qty' => 0, 'supplier_stock_qty' => 47]);
        $this->actingAs($this->approvedBuyer());
        foreach ([route('shop.index'), route('products.show', ['slug' => 'facts-product'])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame('0', $this->textAt($html, 'data-product-local-stock'));
            $this->assertSame('47', $this->textAt($html, 'data-product-supplier-stock'));
            $this->assertStringContainsString(route('cart.items.store'), $html);
        }
    }

    public function test_imported_energy_attribute_renders_f_on_a_to_g_without_fabricated_document_links(): void
    {
        $product = $this->product();
        $this->energySpecification($product, 'F');
        foreach ([route('shop.index'), route('products.show', ['slug' => 'facts-product'])] as $url) {
            $html = $this->get($url)->assertOk()->assertSee('data-energy-label-arrow', false)->getContent();
            $arrow = $this->elementAt($html, 'data-energy-label-arrow');
            $this->assertSame('span', $arrow->tagName);
            $this->assertStringContainsString('F', $arrow->textContent);
            $this->assertStringContainsString('A–G', $arrow->textContent);
            $this->assertStringNotContainsString('data-product-information-sheet', $html);
            $this->assertStringNotContainsString('ec.europa.eu', $html);
        }
        $this->assertDatabaseCount((new ProductEnergyDeclaration)->getTable(), 0);
    }

    public function test_all_card_styles_preserve_the_same_identifiers_private_stock_and_energy_declaration(): void
    {
        $product = $this->product();
        $this->energySpecification($product, 'F');
        $product->load(['translations', 'media', 'energyDeclarations', 'technicalSpecificationRows']);
        $this->actingAs($this->approvedBuyer());
        foreach ([[true, true], [true, false], [false, false]] as [$flat, $lined]) {
            $html = Blade::render('<x-front.desktop.product-card :product="$product" :flat="$flat" :lined="$lined" />', compact('product', 'flat', 'lined'));
            $this->assertSame('BT59-00231', $this->textAt($html, 'data-product-code'));
            $this->assertSame('3850123456789', $this->textAt($html, 'data-product-ean'));
            $this->assertSame('98.237', $this->textAt($html, 'data-product-supplier-stock'));
            $this->assertStringContainsString('F', $this->elementAt($html, 'data-energy-label-arrow')->textContent);
        }
    }

    public function test_invalid_or_conflicting_imported_energy_classes_are_not_invented_and_real_declarations_win(): void
    {
        $product = $this->product();
        $this->energySpecification($product, 'F');
        $this->energySpecification($product, 'E', 'another-energy');
        $product->load('technicalSpecificationRows');
        $presenter = app(ProductEnergyLabelPresenter::class);
        $this->assertNull($presenter->primaryDeclaration($product));
        $product->technicalSpecificationRows()->delete();
        $this->energySpecification($product, '<script>F</script>');
        $product->load('technicalSpecificationRows');
        $this->assertNull($presenter->primaryDeclaration($product));
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id, 'context_code' => 'manual', 'energy_class' => 'C',
            'scale_min' => 'A', 'scale_max' => 'G', 'is_primary' => true, 'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);
        $product->load('energyDeclarations');
        $this->assertSame('C', $presenter->primaryDeclaration($product)['energy_class']);
        DB::enableQueryLog();
        $queryCount = count(DB::getQueryLog());
        $presenter->primaryDeclaration($product);
        $this->assertSame($queryCount, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public static function deniedStates(): array
    {
        return [['guest'], ['no_account'], ['pending'], ['suspended'], ['inactive_primary_group']];
    }

    private function product(): Product
    {
        $product = Product::query()->create([
            'code' => 'herrera-oc-product-123', 'sku' => 'INTERNAL-SKU', 'barcode' => null,
            'is_active' => true, 'base_price' => 19.95, 'stock_qty' => 35791, 'supplier_stock_qty' => 98237,
            'payload' => ['opencart' => ['model' => 'BT59-00231', 'ean' => '3850123456789']],
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Herrera facts product', 'slug' => 'facts-product']);

        return $product;
    }

    private function approvedBuyer(): User
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'facts-approved', 'name' => 'Facts approved', 'is_active' => true]);
        B2BAccount::query()->create([
            'user_id' => $user->id, 'company_name' => 'Facts buyer', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
        ]);

        return $user;
    }

    private function energySpecification(Product $product, string $class, string $key = 'energy-class'): void
    {
        CatalogProductSpecification::query()->create([
            'product_id' => $product->id, 'source' => 'herrera-opencart', 'source_key' => $key,
            'group_name' => 'Specifikacije', 'item_name' => 'Razina energetske učinkovitosti', 'values' => [$class],
        ]);
    }

    private function textAt(string $html, string $marker): string
    {
        return trim($this->elementAt($html, $marker)->textContent);
    }

    private function elementAt(string $html, string $marker): \DOMElement
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $nodes = (new \DOMXPath($document))->query('//*[@'.$marker.']');
        $this->assertGreaterThan(0, $nodes->length, $marker);

        return $nodes->item(0);
    }
}
