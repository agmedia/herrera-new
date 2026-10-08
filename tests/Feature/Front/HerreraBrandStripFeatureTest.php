<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class HerreraBrandStripFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        config(['app.locale' => 'hr']);
    }

    public function test_carousel_includes_more_than_ten_real_catalog_brands_with_priority_order_and_usable_links(): void
    {
        $brands = ['Videx', 'Weicon', 'Vayox', 'Tehnoplast', 'Brock', 'Portwest', 'Midea', 'Gewiss', 'ETI', 'Wago', 'Struhm', 'Braytron'];
        foreach ($brands as $name) {
            $this->manufacturer(strtolower($name), $name);
        }

        $html = $this->renderStrip();
        $xpath = $this->xpath($html);
        $links = $xpath->query('//a[@data-herrera-brand]');
        $this->assertSame(12, $links->count());
        $this->assertSame(['braytron', 'struhm', 'wago', 'eti', 'gewiss', 'midea', 'portwest'],
            array_map(fn ($node) => $node->getAttribute('data-herrera-brand'), array_slice(iterator_to_array($links), 0, 7)));
        foreach ($links as $link) {
            $key = $link->getAttribute('data-herrera-brand');
            $this->assertSame(route('manufacturers.show', ['slug' => $key]), $link->getAttribute('href'));
            $this->assertSame(1, $xpath->query('.//img[@src="'.asset(config('manufacturer-logo-assets.'.$key.'.path')).'"]', $link)->count());
        }
        $carousel = $xpath->query('//*[@data-herrera-brand-carousel and @data-herrera-home-carousel]')->item(0);
        $this->assertNotNull($carousel);
        $options = json_decode($carousel->getAttribute('data-splide'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('loop', $options['type']);
        $this->assertSame('Prethodna', $options['i18n']['last']);
        $this->assertSame('Sljedeća', $options['i18n']['first']);
        $this->assertSame(7, $options['perPage']);
        $this->assertSame(6, $options['breakpoints'][1439]['perPage']);
        $this->assertSame(2, $options['breakpoints'][767]['perPage']);
        $this->assertTrue($options['drag']);
        $this->assertSame(2, $xpath->query('//*[@data-herrera-brand-carousel]//button[contains(@class,"splide__arrow")]')->count());
        $this->assertSame(12, $xpath->query('//*[@data-herrera-brand-carousel]//*[contains(concat(" ",normalize-space(@class)," ")," splide__slide ")]')->count());
        $this->assertStringNotContainsString('class="herrera-eyebrow"', $html);
    }

    public function test_uploaded_logos_are_included_without_a_bundled_mapping_and_override_bundled_artwork(): void
    {
        $logos = [];
        foreach (['custom-uploaded' => 'Uploaded manufacturer', 'vayox' => 'Vayox'] as $slug => $name) {
            $manufacturer = $this->manufacturer($slug, $name);
            $file = UploadedFile::fake()->image($slug.'.png', 300, 120);
            $media = $manufacturer->addMedia($file->getPathname())->toMediaCollection('manufacturer_logo');
            $logos[] = $media->getUrl();
        }

        $html = $this->renderStrip();
        $this->assertSame(2, $this->xpath($html)->query('//a[@data-herrera-brand]')->count());
        foreach ($logos as $logo) {
            $this->assertStringContainsString($logo, $html);
        }
        $this->assertStringNotContainsString(asset('assets/brands/vayox.svg'), $html);
        $this->assertStringContainsString(route('manufacturers.show', ['slug' => 'custom-uploaded']), $html);
    }

    public function test_inactive_empty_unlinked_and_unverified_brands_are_excluded(): void
    {
        $this->manufacturer('wago', 'Wago');
        $this->manufacturer('videx', 'Videx', ['is_active' => false]);
        $this->manufacturer('brock', 'Brock', withProduct: false);
        $this->manufacturer('weicon', 'Weicon', activeProduct: false);
        $this->manufacturer('vayox', 'Vayox', slug: '');
        $this->manufacturer('eti', 'ETI', locale: 'de');
        $this->manufacturer('unverified', 'Unverified', [
            'payload' => ['opencart' => ['image' => 'catalog/325197-46479-Barkan-TV-Bracket.jpg']],
        ]);

        $html = $this->renderStrip();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//a[@data-herrera-brand]')->count());
        $this->assertSame('wago', $xpath->query('//a[@data-herrera-brand]')->item(0)->getAttribute('data-herrera-brand'));
        $this->assertSame(0, $xpath->query('//button[contains(@class,"splide__arrow")]')->count());
        $this->assertStringNotContainsString('Barkan-TV-Bracket.jpg', $html);
    }

    public function test_translated_manufacturer_slug_and_label_follow_the_current_locale(): void
    {
        $manufacturer = $this->manufacturer('vayox', 'Vayox');
        $manufacturer->translations()->create(['locale' => 'en', 'name' => 'Vayox English', 'slug' => 'vayox-en']);
        app()->setLocale('en');

        $html = $this->renderStrip();
        $this->assertStringContainsString(route('manufacturers.show', ['slug' => 'vayox-en']), $html);
        $this->assertStringContainsString('alt="Vayox English"', $html);
    }

    private function manufacturer(string $code, string $name, array $attributes = [], bool $withProduct = true, bool $activeProduct = true, string $locale = 'hr', ?string $slug = null): Manufacturer
    {
        $manufacturer = Manufacturer::query()->create(array_replace(['code' => $code, 'is_active' => true], $attributes));
        $manufacturer->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => $slug ?? $code]);
        if ($withProduct) {
            Product::query()->create([
                'code' => 'BRAND-PRODUCT-'.$code, 'sku' => 'BRAND-'.$code,
                'manufacturer_id' => $manufacturer->id, 'is_active' => $activeProduct,
                'base_price' => 10, 'stock_qty' => 5,
            ]);
        }

        return $manufacturer;
    }

    private function renderStrip(): string
    {
        return Blade::render('@include("front.partials.herrera-brand-strip")');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($document);
    }
}
