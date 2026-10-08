<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraRelatedProductsCarouselFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'store_product_desktop_default_cols' => 4,
            'store_product_mobile_default_cols' => 1,
        ]);
    }

    public function test_related_carousel_keeps_twelve_real_products_in_imported_order_and_six_four_two_pages(): void
    {
        $product = $this->product('main');
        $related = collect(range(1, 14))->map(fn ($index) => $this->product('related-'.$index));
        $inactive = $this->product('inactive', false);
        $product->update(['payload' => ['related_product_ids' => array_merge([$inactive->id], $related->pluck('id')->all())]]);

        $response = $this->get(route('products.show', ['slug' => 'related-carousel-main', 'cols' => 1]))
            ->assertOk()->assertDontSee('9876.54', false)->assertDontSee('data-product-card-form', false)
            ->assertSee('herrera-product-related.css', false);
        $xpath = $this->xpath($response->getContent());
        $carousel = $xpath->query('//*[@data-herrera-related-products-splide]')->item(0);
        $this->assertNotNull($carousel);
        $cards = $xpath->query('.//*[@data-product-card]', $carousel);
        $this->assertSame($related->take(12)->pluck('id')->map(fn ($id) => (string) $id)->all(),
            array_map(fn ($node) => $node->getAttribute('data-product-id'), iterator_to_array($cards)));
        $options = json_decode($carousel->getAttribute('data-splide'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('loop', $options['type']);
        $this->assertSame('Prethodna', $options['i18n']['last']);
        $this->assertSame('Sljedeća', $options['i18n']['first']);
        $this->assertFalse($options['rewind']);
        $this->assertSame(6, $options['perPage']);
        $this->assertSame(6, $options['breakpoints'][1280]['perPage']);
        foreach ([1279, 1024, 860] as $breakpoint) {
            $this->assertSame(4, $options['breakpoints'][$breakpoint]['perPage']);
        }
        foreach ([767, 640] as $breakpoint) {
            $this->assertSame(2, $options['breakpoints'][$breakpoint]['perPage']);
        }
        $this->assertSame(1, $options['perMove']);
        $this->assertTrue($options['drag']);
        $this->assertFalse($options['pagination']);
        $this->assertFalse($options['breakpoints'][640]['pagination']);
        $this->assertSame(2, $xpath->query('.//button[contains(@class, "splide__arrow") and @aria-label]', $carousel)->count());
        $this->assertFalse($carousel->hasAttribute('style'));
    }

    public function test_single_related_product_does_not_offer_empty_carousel_navigation(): void
    {
        $product = $this->product('main');
        $related = $this->product('only');
        $product->update(['payload' => ['related_product_ids' => [$related->id]]]);
        $response = $this->get(route('products.show', ['slug' => 'related-carousel-main']))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $carousel = $xpath->query('//*[@data-herrera-related-products-splide]')->item(0);
        $this->assertNotNull($carousel);
        $options = json_decode($carousel->getAttribute('data-splide'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('slide', $options['type']);
        $this->assertSame(1, $options['perPage']);
        $this->assertFalse($options['arrows']);
        $this->assertFalse($options['drag']);
        $this->assertSame('1', $carousel->getAttribute('data-product-count'));
        $this->assertSame(1, $xpath->query('.//*[@data-product-card]', $carousel)->count());
        $this->assertSame(0, $xpath->query('.//button[contains(@class, "splide__arrow")]', $carousel)->count());
    }

    public function test_other_storefront_keeps_its_existing_related_limit_and_initializer(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Termol',
            'store_product_desktop_default_cols' => 5,
        ]);
        $product = $this->product('main');
        $related = collect(range(1, 8))->map(fn ($index) => $this->product('related-'.$index));
        $product->update(['payload' => ['related_product_ids' => $related->pluck('id')->all()]]);
        $response = $this->get(route('products.show', ['slug' => 'related-carousel-main']))->assertOk()
            ->assertDontSee('data-herrera-related-products-splide', false)->assertDontSee('herrera-product-related.css', false);
        $xpath = $this->xpath($response->getContent());
        $carousel = $xpath->query('//*[@id="related-products-carousel-'.$product->id.'"]')->item(0);
        $this->assertNotNull($carousel);
        $this->assertSame(5, $xpath->query('.//*[@data-product-card]', $carousel)->count());
        $this->assertSame('5', $carousel->getAttribute('data-desktop-cols'));
        $this->assertSame('1', $carousel->getAttribute('data-mobile-cols'));
        $this->assertFalse($carousel->hasAttribute('data-splide'));
    }

    public function test_recent_history_uses_the_same_carousel_and_keeps_twelve_available_viewed_products_in_order(): void
    {
        $product = $this->product('main');
        $viewed = collect(range(1, 14))->map(fn ($index) => $this->product('viewed-'.$index))->reverse()->values();
        $inactive = $this->product('inactive', false);
        $neverViewed = $this->product('never-viewed');
        $history = array_merge([$product->id, $viewed->first()->id, $viewed->first()->id, $inactive->id, 999999, 0], $viewed->pluck('id')->all());

        $response = $this->withSession(['front_recently_viewed_products' => $history])
            ->get(route('products.show', ['slug' => 'related-carousel-main']))->assertOk()
            ->assertSessionHas('front_recently_viewed_products', fn ($ids) => $ids[0] === $product->id && count($ids) === count(array_unique($ids)) && count($ids) <= 24);
        $xpath = $this->xpath($response->getContent());
        $recent = $xpath->query('//*[@data-herrera-recently-viewed-products-splide]')->item(0);
        $related = $xpath->query('//*[@data-herrera-related-products-splide]')->item(0);
        $this->assertNotNull($recent);
        $this->assertNotNull($related);
        $cards = $xpath->query('.//*[@data-product-card]', $recent);
        $cardIds = array_map(fn ($node) => $node->getAttribute('data-product-id'), iterator_to_array($cards));
        $this->assertSame($viewed->take(12)->pluck('id')->map(fn ($id) => (string) $id)->all(), $cardIds);
        $this->assertNotContains((string) $neverViewed->id, $cardIds);
        $this->assertNotContains((string) $product->id, $cardIds);
        $this->assertSame($related->getAttribute('data-splide'), $recent->getAttribute('data-splide'));
        $this->assertSame('6', $recent->getAttribute('data-desktop-cols'));
        $this->assertSame('2', $recent->getAttribute('data-mobile-cols'));
        $this->assertSame(2, $xpath->query('.//button[contains(@class, "splide__arrow") and @aria-label]', $recent)->count());
        $this->assertSame(1, $xpath->query('//link[contains(@href, "herrera-product-related.css")]')->count());
    }

    public function test_recent_carousel_is_absent_without_history_and_has_no_navigation_for_one_viewed_product(): void
    {
        $product = $this->product('main');
        $viewed = $this->product('viewed');
        $this->product('never-viewed');
        $this->get(route('products.show', ['slug' => 'related-carousel-main']))->assertOk()
            ->assertDontSee('data-herrera-recently-viewed-products-splide', false);

        $response = $this->withSession(['front_recently_viewed_products' => [$product->id, $viewed->id, $viewed->id]])
            ->get(route('products.show', ['slug' => 'related-carousel-main']))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $carousel = $xpath->query('//*[@data-herrera-recently-viewed-products-splide]')->item(0);
        $this->assertNotNull($carousel);
        $options = json_decode($carousel->getAttribute('data-splide'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('slide', $options['type']);
        $this->assertSame(6, $options['perPage']);
        $this->assertSame(6, $options['breakpoints'][1280]['perPage']);
        $this->assertFalse($options['arrows']);
        $this->assertFalse($options['drag']);
        $this->assertSame(1, $xpath->query('.//*[@data-product-card]', $carousel)->count());
        $this->assertSame(0, $xpath->query('.//button[contains(@class, "splide__arrow")]', $carousel)->count());
    }

    public function test_small_recent_collection_loops_without_repeating_cards_in_the_first_page(): void
    {
        $this->product('main');
        $viewed = collect([$this->product('first-viewed'), $this->product('second-viewed')]);
        $response = $this->withSession(['front_recently_viewed_products' => $viewed->pluck('id')->all()])
            ->get(route('products.show', ['slug' => 'related-carousel-main']))->assertOk();
        $carousel = $this->xpath($response->getContent())->query('//*[@data-herrera-recently-viewed-products-splide]')->item(0);
        $this->assertNotNull($carousel);
        $options = json_decode($carousel->getAttribute('data-splide'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('loop', $options['type']);
        $this->assertSame(2, $options['perPage']);
        $this->assertSame(2, $options['breakpoints'][1280]['perPage']);
        $this->assertSame(2, $options['breakpoints'][767]['perPage']);
        $this->assertTrue($options['arrows']);
        $this->assertTrue($options['drag']);
    }

    public function test_other_storefront_recent_history_keeps_its_existing_carousel_and_lookup_limit(): void
    {
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Termol']);
        $product = $this->product('main');
        $viewed = collect(range(1, 14))->map(fn ($index) => $this->product('viewed-'.$index))->reverse()->values();
        $response = $this->withSession(['front_recently_viewed_products' => $viewed->pluck('id')->all()])
            ->get(route('products.show', ['slug' => 'related-carousel-main']))->assertOk()
            ->assertDontSee('data-herrera-recently-viewed-products-splide', false)->assertDontSee('herrera-product-related.css', false);
        $xpath = $this->xpath($response->getContent());
        $carousel = $xpath->query('//*[@id="recently-viewed-products-carousel-'.$product->id.'"]')->item(0);
        $this->assertNotNull($carousel);
        $cards = $xpath->query('.//*[@data-product-card]', $carousel);
        $this->assertSame($viewed->take(12)->pluck('id')->map(fn ($id) => (string) $id)->all(),
            array_map(fn ($node) => $node->getAttribute('data-product-id'), iterator_to_array($cards)));
        $this->assertSame('5', $carousel->getAttribute('data-desktop-cols'));
        $this->assertSame('1', $carousel->getAttribute('data-mobile-cols'));
        $this->assertFalse($carousel->hasAttribute('data-splide'));
    }

    private function product(string $code, bool $active = true): Product
    {
        $product = Product::query()->create([
            'code' => 'RELATED-CAROUSEL-'.$code,
            'is_active' => $active,
            'base_price' => 9876.54,
            'stock_qty' => 3,
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => 'Related carousel '.$code,
            'slug' => 'related-carousel-'.$code,
        ]);

        return $product;
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
