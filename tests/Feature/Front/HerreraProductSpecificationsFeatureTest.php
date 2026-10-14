<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraProductSpecificationsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_facts_are_visible_next_to_purchase_controls_and_other_facts_expand_without_a_duplicate_lower_section(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $product = $this->product('<p>Rasvjeta za vaš projekt.</p><p>Snaga: 20 W<br>Temperatura boje: 4000 K</p>');
        $attribute = Attribute::query()->create(['code' => 'light-power', 'group_code' => 'snaga', 'type' => 'select', 'is_active' => true]);
        $attribute->translations()->create(['locale' => 'hr', 'group_name' => 'Snaga', 'name' => '20 W', 'slug' => '20-w']);
        $product->attributes()->attach($attribute);
        $this->spec($product, 'Snaga', '20', 'W');
        $this->spec($product, 'Napon', '230', 'V');
        $this->spec($product, 'Jamstvo', '3 godine');

        $response = $this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()
            ->assertSee('Rasvjeta za vaš projekt.')->assertDontSee('id="product-specifications"', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//*[@data-product-purchase-information]//*[@data-herrera-product-specifications]')->length);
        $this->assertSame(['Temperatura boje', 'Snaga', 'Jamstvo'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($xpath->query('//*[@data-product-primary-specifications]//dt'))));
        $this->assertSame(1, $xpath->query('//*[@data-product-secondary-specifications and not(@open)]//dt[text()="Napon"]')->length);
        $this->assertSame(1, $xpath->query('//dt[text()="Snaga"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="product-description"]//*[contains(text(), "Snaga:")]')->length);
        $this->assertSame(3, $product->technicalSpecificationRows()->count());
        $this->assertStringContainsString('Snaga: 20 W', $product->translations()->first()->description);
    }

    public function test_description_table_facts_are_extracted_without_losing_narrative_links_or_ordinary_prose(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $product = $this->product('<p>Detalji: namijenjeno <strong>unutarnjoj uporabi</strong>. <a href="/contact">Javite nam se</a>.</p><table><tr><td>Dimenzije</td><td>120 x 40 mm</td></tr><tr><td>Materijal</td><td>Aluminij</td></tr></table>');

        $response = $this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()
            ->assertSee('120 x 40 mm')->assertSee('Aluminij')->assertSee('<strong>unutarnjoj uporabi</strong>', false)
            ->assertSee('href="/contact"', false)->assertSee('Detalji: namijenjeno');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//*[@id="product-description"]//table')->length);
        $this->assertSame(1, $xpath->query('//*[@data-product-primary-specifications]//dt[text()="Dimenzije"]')->length);
        $this->assertSame(0, $product->technicalSpecificationRows()->count());
    }

    public function test_products_without_facts_do_not_render_an_empty_specification_panel(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->product('<p>Klasična svjetiljka.</p>');
        $this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()->assertDontSee('data-herrera-product-specifications', false);
    }

    public function test_fact_extraction_preserves_images_and_narrative_tables_in_the_description(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->product('<p>Snaga: 20 W<br><img src="/project.jpg" alt="Projekt"></p><table><tr><th>Upute za projekt i montažu</th></tr></table>');

        $response = $this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()
            ->assertSee('src="/project.jpg"', false)->assertSee('Upute za projekt i montažu');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//*[@id="product-description"]//img')->length);
        $this->assertSame(1, $xpath->query('//*[@id="product-description"]//table//th')->length);
    }

    public function test_fact_links_are_preserved_and_inline_fact_fragments_do_not_repeat_below(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->product('<strong>Snaga:</strong> 20 W<br><p>Jamstvo: <a href="/warranty.pdf">3 godine</a></p><p>Opis namjene.</p>');

        $response = $this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()
            ->assertSee('href="/warranty.pdf"', false)->assertSee('Opis namjene.');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//*[@id="product-description"]//strong[text()="Snaga:"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-product-primary-specifications]//dt[text()="Snaga"]')->length);
    }

    public function test_plain_text_facts_and_unit_spacing_are_deduplicated(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $product = $this->product("Snaga: 20 W\nNapon: 230 V\nOpis namjene.");
        $this->spec($product, 'Snaga', '20W');

        $response = $this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()->assertSee('Opis namjene.');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//*[@data-product-primary-specifications]//dd[text()="20W"]')->length);
        $this->assertStringNotContainsString('Snaga:', $xpath->query('//*[@id="product-description"]')->item(0)->textContent);
        $this->assertStringNotContainsString('Napon:', $xpath->query('//*[@id="product-description"]')->item(0)->textContent);
    }

    public function test_escaped_comparisons_are_readable_and_do_not_duplicate_the_same_value(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $product = $this->product('<p>Faktor snage: &gt;0,5</p><p>Vrijeme početka: &lt;0,5 s</p>');
        $this->spec($product, 'Faktor snage', '&amp;gt; 0,5');
        $this->spec($product, 'Vrijeme početka', '&lt; 0,5 s');

        $xpath = $this->xpath($this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()->getContent());
        $values = array_map(fn ($node) => trim($node->textContent), iterator_to_array($xpath->query('//*[@data-product-secondary-specifications]//dd')));
        $this->assertSame(['> 0,5', '< 0,5 s'], $values);
        $description = $xpath->query('//*[@id="product-description"]');
        $this->assertSame(0, $description->length);
    }

    public function test_literal_less_than_comparison_in_description_only_is_preserved_as_a_fact(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->product("Vrijeme početka: <0,5 s\nOpis namjene.");

        $xpath = $this->xpath($this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()->getContent());
        $this->assertSame('< 0,5 s', trim($xpath->query('//*[@data-product-secondary-specifications]//dd')->item(0)->textContent));
    }

    public function test_other_stores_keep_their_existing_specification_section(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Termol');
        $product = $this->product('<p>Klasična svjetiljka.</p>');
        $this->spec($product, 'Snaga', '10 W');
        $this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()
            ->assertSee('id="product-specifications"', false)->assertDontSee('data-herrera-product-specifications', false);
    }

    public function test_imported_fact_lines_are_removed_together_with_their_break_tags(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $description = "Utičnica E27<br>\r\nSnaga: 9 W<br>\r\nNapon: 230 V<br>\r\nMaterijal: plastika";
        $product = $this->product($description);

        $xpath = $this->xpath($this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()->getContent());
        $this->assertSame('Utičnica E27', trim($xpath->query('//*[@class="product-detail-description-content"]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//*[@id="product-description"]//br')->length);
        $this->assertSame($description, $product->translations()->first()->description);
    }

    public function test_nested_imported_fact_lines_do_not_leave_blank_lines_between_remaining_prose(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->product("<p>Utičnica E27<br>\r\nSnaga: 9 W<br>\r\nNapon: 230 V<br>\r\nZa unutarnju uporabu.</p>");

        $xpath = $this->xpath($this->get(route('products.show', ['slug' => 'compact-facts']))->assertOk()->getContent());
        $this->assertSame(1, $xpath->query('//*[@id="product-description"]//br')->length);
        $this->assertStringContainsString('Utičnica E27', $xpath->query('//*[@id="product-description"]')->item(0)->textContent);
        $this->assertStringContainsString('Za unutarnju uporabu.', $xpath->query('//*[@id="product-description"]')->item(0)->textContent);
    }

    private function product(string $description): Product
    {
        $product = Product::query()->create(['code' => 'COMPACT-FACTS', 'is_active' => true, 'stock_qty' => 3, 'base_price' => 20]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Svjetiljka', 'slug' => 'compact-facts', 'description' => $description]);

        return $product;
    }

    private function spec(Product $product, string $name, string $value, string $measure = ''): void
    {
        $product->technicalSpecificationRows()->create(['source' => 'manual', 'source_key' => $name, 'group_name' => 'Specifikacije', 'item_name' => $name, 'values' => [$value], 'measure' => $measure]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
