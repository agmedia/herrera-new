<?php

namespace Tests\Feature\Feeds;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class NabavaNetFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nabava_net.enabled' => true,
            'services.nabava_net.username' => 'nabava-test-user',
            'services.nabava_net.password' => 'nabava-test-password',
            'services.nabava_net.locale' => 'hr',
            'services.nabava_net.storefront_url' => 'https://shop.termol.test',
        ]);

        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
    }

    public function test_feed_fails_closed_when_disabled_or_credentials_are_invalid(): void
    {
        config(['services.nabava_net.enabled' => false]);
        $this->get('/feeds/nabava.xml')->assertNotFound();

        config(['services.nabava_net.enabled' => true]);
        $this->get('/feeds/nabava.xml')->assertUnauthorized();
        $this->get('/feeds/nabava.xml?username=wrong&password=wrong')->assertUnauthorized();
        $this->get('/feeds/nabava.xml?username[]=nabava-test-user&password[]=nabava-test-password')
            ->assertUnauthorized();
    }

    public function test_feed_matches_legacy_schema_with_current_urls_and_safe_xml_text(): void
    {
        $root = $this->category('heating', 'Grijanje');
        $leaf = $this->category('boilers', 'Bojleri', $root);
        $manufacturer = Manufacturer::query()->create([
            'code' => 'vaillant',
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 1,
        ]);
        $manufacturer->translations()->create([
            'locale' => 'hr',
            'name' => 'Vaillant',
            'slug' => 'vaillant',
        ]);

        $product = $this->product([
            'code' => 'termol-boiler-1',
            'sku' => 'BOILER-1',
            'base_price' => 1234.56,
            'manufacturer_id' => $manufacturer->id,
        ], [
            'name' => 'Bojler & grijač <Pro>',
            'slug' => 'bojler-grijac-pro',
            'description' => '<p>Topla &amp; hladna <strong>voda</strong></p><script>ne prikazuj</script><p>Drugi red</p>',
        ]);
        $product->categories()->attach($leaf->id, ['sort_order' => 1, 'is_primary' => true]);
        $image = UploadedFile::fake()->image('boiler.jpg', 800, 800);
        $product->addMedia($image->getPathname())
            ->usingFileName('boiler.jpg')
            ->toMediaCollection('product_main');

        $withoutOptionalFields = $this->product([
            'code' => 'termol-no-optionals',
            'sku' => 'NO-OPTIONALS',
            'base_price' => 19.99,
        ], [
            'name' => 'Artikl bez slike',
            'slug' => 'artikl-bez-slike',
            'description' => null,
            'excerpt' => null,
        ]);
        $withoutOptionalFields->categories()->attach($leaf->id, ['sort_order' => 2, 'is_primary' => true]);

        $response = $this->withHeader('Host', 'untrusted.example')->get($this->feedUrl());
        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $content = $response->streamedContent();
        $xml = simplexml_load_string($content);

        $this->assertNotFalse($xml);
        $this->assertSame('artikli', $xml->getName());
        $this->assertCount(2, $xml->artikl);

        $items = collect(iterator_to_array($xml->artikl, false))
            ->keyBy(static fn ($item): string => (string) $item->sifra);
        $item = $items->get('BOILER-1');
        $this->assertNotNull($item);
        $this->assertSame('Grijanje > Bojleri > Vaillant', (string) $item->kategorija);
        $this->assertSame('Bojler & grijač <Pro>', (string) $item->naziv_artikla);
        $this->assertSame('1234,56 €', (string) $item->cijena);
        $this->assertSame('Raspoloživo', (string) $item->raspolozivost);
        $baseUrl = 'https://shop.termol.test';
        $this->assertSame($baseUrl.'/product/bojler-grijac-pro', (string) $item->link_na_artikl);
        $this->assertStringStartsWith($baseUrl.'/storage/', (string) $item->link_na_sliku_artikla);
        $this->assertStringEndsWith('/boiler.jpg', (string) $item->link_na_sliku_artikla);
        $this->assertSame("Topla & hladna voda\nDrugi red", (string) $item->detaljni_opis);
        $this->assertStringNotContainsString('ne prikazuj', (string) $item->detaljni_opis);

        $minimalItem = $items->get('NO-OPTIONALS');
        $this->assertNotNull($minimalItem);
        $this->assertFalse(isset($minimalItem->link_na_sliku_artikla));
        $this->assertFalse(isset($minimalItem->detaljni_opis));

        $this->assertStringContainsString('&amp;', $content);
        $this->assertStringContainsString('&lt;Pro&gt;', $content);
        $this->assertElementsAppearInOrder($content, [
            '<sifra>',
            '<kategorija>',
            '<naziv_artikla>',
            '<cijena>',
            '<raspolozivost>',
            '<link_na_artikl>',
            '<link_na_sliku_artikla>',
            '<detaljni_opis>',
        ]);
    }

    public function test_feed_only_exports_active_products_that_can_be_purchased(): void
    {
        $available = $this->product([
            'code' => 'AVAILABLE-CODE',
            'sku' => 'AVAILABLE-SKU',
            'base_price' => 10,
            'stock_qty' => 2,
        ], ['name' => 'Dostupan artikl', 'slug' => 'dostupan-artikl']);
        $this->assertNotNull($available);

        $this->product([
            'code' => 'OUT-CODE',
            'sku' => 'OUT-SKU',
            'base_price' => 20,
            'stock_qty' => 0,
        ], ['name' => 'Nema zalihe', 'slug' => 'nema-zalihe']);

        $this->product([
            'code' => 'INACTIVE-CODE',
            'sku' => 'INACTIVE-SKU',
            'base_price' => 30,
            'stock_qty' => 5,
            'is_active' => false,
        ], ['name' => 'Neaktivan artikl', 'slug' => 'neaktivan-artikl']);

        $xml = simplexml_load_string($this->get($this->feedUrl())->streamedContent());

        $this->assertNotFalse($xml);
        $this->assertCount(1, $xml->artikl);
        $this->assertSame('AVAILABLE-SKU', (string) $xml->artikl[0]->sifra);
        $this->assertSame('Ostalo', (string) $xml->artikl[0]->kategorija);
    }

    public function test_feed_uses_public_promotional_price(): void
    {
        $product = $this->product([
            'code' => 'PROMO-CODE',
            'sku' => 'PROMO-SKU',
            'base_price' => 100,
        ], ['name' => 'Akcijski artikl', 'slug' => 'akcijski-artikl']);
        $action = CatalogAction::query()->create([
            'code' => 'NABAVA-PROMO',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 20,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $action->targets()->create([
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'target_id' => $product->id,
        ]);

        $xml = simplexml_load_string($this->get($this->feedUrl())->streamedContent());

        $this->assertNotFalse($xml);
        $this->assertSame('80,00 €', (string) $xml->artikl[0]->cijena);
    }

    public function test_feed_converts_net_catalog_price_to_gross_eur_price(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $taxRate = TaxRate::query()->create([
            'code' => 'pdv25-nabava',
            'name' => 'PDV 25%',
            'rate_type' => 'percent',
            'rate' => 25,
            'priority' => 1,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $this->product([
            'code' => 'NET-CODE',
            'sku' => 'NET-SKU',
            'base_price' => 100,
            'tax_rate_id' => $taxRate->id,
        ], ['name' => 'Neto artikl', 'slug' => 'neto-artikl']);

        $xml = simplexml_load_string($this->get($this->feedUrl())->streamedContent());

        $this->assertNotFalse($xml);
        $this->assertSame('125,00 €', (string) $xml->artikl[0]->cijena);
    }

    private function category(string $code, string $name, ?Category $parent = null): Category
    {
        $category = new Category([
            'scope' => Category::SCOPE_CATALOG,
            'code' => $code,
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 1,
        ]);

        if ($parent) {
            $category->appendToNode($parent)->save();
        } else {
            $category->save();
        }

        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG,
            'locale' => 'hr',
            'name' => $name,
            'slug' => $code,
        ]);

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $translation
     */
    private function product(array $attributes, array $translation): Product
    {
        $product = Product::query()->create(array_merge([
            'code' => 'DEFAULT-CODE',
            'sku' => 'DEFAULT-SKU',
            'is_active' => true,
            'base_price' => 10,
            'stock_qty' => 5,
        ], $attributes));

        $product->translations()->create(array_merge([
            'locale' => 'hr',
            'name' => 'Testni artikl',
            'slug' => strtolower((string) $product->sku),
            'excerpt' => null,
            'description' => null,
        ], $translation));

        return $product;
    }

    private function feedUrl(): string
    {
        return '/feeds/nabava.xml?'.http_build_query([
            'username' => 'nabava-test-user',
            'password' => 'nabava-test-password',
        ]);
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function assertElementsAppearInOrder(string $xml, array $needles): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $position = strpos($xml, $needle, $offset);
            $this->assertNotFalse($position, "Missing XML element {$needle}.");
            $offset = $position + strlen($needle);
        }
    }
}
