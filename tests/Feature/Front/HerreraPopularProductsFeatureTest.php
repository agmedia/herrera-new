<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Services\Front\PopularProductsService;
use App\Services\Settings\SystemSettingsService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class HerreraPopularProductsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private int $orderSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'Europe/Zagreb'));
        app()->setLocale('hr');
        config(['app.locale' => 'hr', 'commerce.b2b_only' => true, 'storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'catalog_hide_out_of_stock_products' => false,
        ]);
    }

    public function test_popularity_counts_distinct_orders_before_units_and_preserves_tie_order_and_limit(): void
    {
        $popular = $this->product('popular');
        $bulk = $this->product('bulk');
        $firstTie = $this->product('first-tie');
        $secondTie = $this->product('second-tie');

        foreach (range(1, 3) as $index) {
            $this->item($this->order(), $popular, 1);
        }
        foreach (range(1, 2) as $index) {
            $this->item($this->order(), $bulk, 100);
            $this->item($this->order(), $firstTie, 1);
            $this->item($this->order(), $secondTie, 1);
        }
        // Multiple lines for one product in one order must not inflate its popularity.
        $firstBulkOrder = Order::query()->whereHas('items', fn ($query) => $query->where('product_id', $bulk->id))->firstOrFail();
        $this->item($firstBulkOrder, $bulk, 100);
        $this->item($firstBulkOrder, $bulk, 100);

        $service = app(PopularProductsService::class);
        $this->assertSame([$popular->id, $bulk->id, $firstTie->id, $secondTie->id],
            $service->forStorefront('hr', 'hr')->modelKeys());
        $this->assertSame([$popular->id, $bulk->id], $service->forStorefront('hr', 'hr', 2)->modelKeys());
    }

    public function test_confirmed_native_and_imported_orders_count_but_pending_unmapped_and_cancelled_orders_do_not(): void
    {
        $accepted = [];
        foreach (['herrera-oc-status-3', 'herrera-oc-status-5', 'shipped', 'completed', 'delivered', 'sent'] as $code) {
            $product = $this->product($code);
            $status = $this->createStatus($code, ['is_paid' => false]);
            $this->item($this->order($status, ['source' => str_starts_with($code, 'herrera-oc-') ? 'opencart_import' : 'web']), $product);
            $accepted[] = $product->id;
        }
        $paidFlag = $this->product('paid-flag');
        $this->item($this->order($this->createStatus('custom-payment-status', ['is_paid' => true])), $paidFlag);
        $accepted[] = $paidFlag->id;
        $paidTimestamp = $this->product('paid-timestamp');
        $this->item($this->order($this->createStatus('pending-payment'), ['paid_at' => now()]), $paidTimestamp);
        $accepted[] = $paidTimestamp->id;

        foreach ([
            $this->order($this->createStatus('herrera-oc-status-1', ['name' => 'U obradi'])),
            $this->order(null, ['status_id' => null, 'source' => 'opencart_import', 'payload' => ['opencart' => ['order_status_id' => 0]]]),
            $this->order($this->createStatus('cancelled-paid', ['is_paid' => true, 'is_cancelled' => true]), ['paid_at' => now()]),
            $this->order($this->createStatus('herrera-oc-status-7', ['name' => ' Otkazano ', 'is_paid' => true]), ['paid_at' => now()]),
        ] as $index => $order) {
            $this->item($order, $this->product('excluded-status-'.$index), 1000);
        }

        $this->assertSame($accepted, app(PopularProductsService::class)->forStorefront('hr', 'hr', 20)->modelKeys());
    }

    public function test_rolling_window_uses_placement_time_and_creation_time_only_when_placement_is_missing(): void
    {
        $boundary = $this->product('window-boundary');
        $current = $this->product('window-now');
        $fallback = $this->product('window-creation-fallback');
        $this->item($this->order(null, ['placed_at' => now()->subDays(30)]), $boundary);
        $this->item($this->order(null, ['placed_at' => now()]), $current);
        $this->item($this->order(null, ['placed_at' => null, 'created_at' => now()->subDays(5)]), $fallback);

        foreach ([
            ['placed_at' => now()->subDays(30)->subSecond(), 'created_at' => now()],
            ['placed_at' => now()->addSecond()],
            ['placed_at' => null, 'created_at' => now()->subDays(31)],
            ['placed_at' => now()->subDays(60), 'paid_at' => now(), 'created_at' => now()],
        ] as $index => $attributes) {
            $this->item($this->order(null, $attributes), $this->product('excluded-date-'.$index), 1000);
        }

        $this->assertSame([$boundary->id, $current->id, $fallback->id],
            app(PopularProductsService::class)->forStorefront('hr', 'hr')->modelKeys());
    }

    public function test_inactive_products_are_always_hidden_and_stock_visibility_follows_the_catalog_setting(): void
    {
        $inactive = $this->product('inactive', ['is_active' => false]);
        $local = $this->product('local-stock');
        $supplier = $this->product('supplier-stock', ['stock_qty' => 0, 'supplier_stock_qty' => 25]);
        $unavailable = $this->product('out-of-stock', ['stock_qty' => 0, 'supplier_stock_qty' => 0]);
        $order = $this->order();
        foreach ([$inactive, $local, $supplier, $unavailable] as $product) {
            $this->item($order, $product);
        }

        $settings = app(SystemSettingsService::class);
        $settings->put('catalog_hide_out_of_stock_products', true);
        $this->assertSame([$local->id, $supplier->id],
            app(PopularProductsService::class)->forStorefront('hr', 'hr')->modelKeys());
        $settings->put('catalog_hide_out_of_stock_products', false);
        $this->assertSame([$local->id, $supplier->id, $unavailable->id],
            app(PopularProductsService::class)->forStorefront('hr', 'hr')->modelKeys());
    }

    public function test_only_products_with_usable_current_or_fallback_locale_links_are_returned(): void
    {
        $current = $this->product('current-locale', locale: 'en');
        $fallback = $this->product('fallback-locale');
        $blankCurrent = $this->product('blank-current');
        $blankCurrent->translations()->create(['locale' => 'en', 'name' => 'Blank current slug', 'slug' => '']);
        $wrongLocale = $this->product('wrong-locale', locale: 'de');
        $blankSlug = $this->product('blank-slug', slug: '');
        $order = $this->order();
        foreach ([$current, $fallback, $blankCurrent, $wrongLocale, $blankSlug] as $product) {
            $this->item($order, $product);
        }

        $products = app(PopularProductsService::class)->forStorefront('en', 'hr');
        $this->assertSame([$current->id, $fallback->id, $blankCurrent->id], $products->modelKeys());
        view()->share('errors', new ViewErrorBag);
        foreach ($products as $product) {
            $html = Blade::render('<x-front.desktop.product-card :product="$product" locale="en" fallback-locale="hr" :flat="true" :lined="true" />', compact('product'));
            $this->assertStringContainsString('href="'.route('products.show', ['slug' => 'popular-'.$product->sku]).'"', $html);
            $this->assertStringNotContainsString('href="'.url('/product').'"', $html);
        }
    }

    public function test_default_limit_returns_eight_ranked_products(): void
    {
        $order = $this->order();
        $products = collect(range(1, 9))->map(function ($index) use ($order): Product {
            $product = $this->product('limit-'.$index);
            $this->item($order, $product);

            return $product;
        });

        $this->assertSame($products->take(8)->pluck('id')->all(),
            app(PopularProductsService::class)->forStorefront('hr', 'hr')->modelKeys());
    }

    public function test_no_qualifying_sales_returns_an_empty_collection_without_fabricated_recommendations(): void
    {
        $unsold = $this->product('unsold');
        $this->item($this->order($this->createStatus('pending')), $unsold);
        $this->product('another-unsold');

        $this->assertTrue(app(PopularProductsService::class)->forStorefront('hr', 'hr')->isEmpty());
        $this->get(route('home'))->assertOk()->assertDontSee('data-herrera-popular-products', false);
    }

    public function test_fallback_home_shows_popularity_once_immediately_after_brands_without_guest_prices(): void
    {
        $manufacturer = $this->manufacturer();
        $product = $this->product('homepage-popular', ['manufacturer_id' => $manufacturer->id, 'base_price' => 9876.54]);
        $this->item($this->order(), $product);

        $response = $this->get(route('home'))->assertOk()
            ->assertSeeInOrder(['herrera-brand-strip-title', 'data-herrera-popular-products'], false)
            ->assertDontSee('9876.54', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//main//*[@data-herrera-popular-products]')->count());
        $this->assertSame(1, $xpath->query('//main/section[@aria-labelledby="herrera-brand-strip-title"]/following-sibling::*[1][@data-herrera-popular-products]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-herrera-popular-products]//*[@data-product-card]')->count());
        $carousel = $xpath->query('//*[@data-herrera-popular-products-splide]')->item(0);
        $options = json_decode($carousel->getAttribute('data-splide'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(6, $options['perPage']);
        $this->assertSame(4, $options['breakpoints'][1279]['perPage']);
        $this->assertSame(2, $options['breakpoints'][767]['perPage']);
        $this->assertTrue($options['destroy']);
        $this->assertTrue($options['breakpoints'][767]['destroy']);
        $this->assertSame(0, $xpath->query('//*[@data-herrera-popular-products]//*[@data-product-card-form]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-herrera-popular-products]//a[@href="'.route('categories.index').'"]')->count());
        $this->assertSame(2, $xpath->query('//main//*[@data-herrera-home-carousel and (@data-herrera-home-products-splide or @data-herrera-popular-products-splide)]')->count());
    }

    public function test_managed_home_inserts_popularity_after_the_first_brand_block_and_keeps_following_content(): void
    {
        $manufacturer = $this->manufacturer();
        $product = $this->product('managed-home-popular', ['manufacturer_id' => $manufacturer->id, 'base_price' => 9876.54]);
        $this->item($this->order(), $product);
        $this->block('managed-hero', 'rich_text', 'home.hero', 'Managed hero');
        $brands = $this->block('managed-brands', 'popular_brands', 'home.after_products', 'Managed brands');
        $brands->items()->create(['item_type' => 'manufacturer', 'item_id' => $manufacturer->id, 'sort_order' => 0]);
        $this->block('following-content', 'rich_text', 'home.after_products', 'Following content', 1);
        $this->block('second-brands', 'popular_brands', 'home.bottom', 'Second brands');

        $response = $this->get(route('home'))->assertOk()
            ->assertSeeInOrder(['Managed hero', 'Managed brands', 'data-herrera-popular-products', 'Following content', 'Second brands'], false)
            ->assertDontSee('9876.54', false)
            ->assertDontSee('herrera-brand-strip-title', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(2, $xpath->query('//main//*[@data-popular-brands]')->count());
        $this->assertSame(1, $xpath->query('//main//*[@data-herrera-popular-products]')->count());
        $this->assertSame(1, $xpath->query('(//main//*[@data-popular-brands])[1]/following-sibling::*[1][@data-herrera-popular-products]')->count());
        $this->assertSame(0, $xpath->query('//*[@data-herrera-popular-products]//*[@data-product-card-form]')->count());
    }

    private function manufacturer(): Manufacturer
    {
        $manufacturer = Manufacturer::query()->create(['code' => 'braytron', 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => 'Braytron', 'slug' => 'braytron']);

        return $manufacturer;
    }

    private function block(string $code, string $type, string $placement, string $title, int $sortOrder = 0): ContentBlock
    {
        $block = ContentBlock::query()->create(['code' => $code, 'name' => $title, 'type' => $type, 'is_active' => true]);
        $block->translations()->create(['locale' => 'hr', 'title' => $title]);
        $block->slots()->create(['placement' => $placement, 'frontend_variant' => 'desktop', 'sort_order' => $sortOrder, 'is_active' => true]);

        return $block;
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

    private function product(string $code, array $attributes = [], string $locale = 'hr', ?string $slug = null): Product
    {
        $product = Product::query()->create(array_replace([
            'code' => 'POPULAR-'.$code, 'sku' => $code, 'is_active' => true,
            'stock_qty' => 5, 'supplier_stock_qty' => 0, 'base_price' => 10,
        ], $attributes));
        $product->translations()->create(['locale' => $locale, 'name' => 'Popular '.$code, 'slug' => $slug ?? 'popular-'.$code]);

        return $product;
    }

    private function createStatus(string $code, array $attributes = []): OrderStatus
    {
        return OrderStatus::query()->firstOrCreate(['code' => $code], array_replace([
            'name' => ucfirst($code), 'is_paid' => false, 'is_cancelled' => false, 'is_active' => true,
        ], $attributes));
    }

    private function order(?OrderStatus $status = null, array $attributes = []): Order
    {
        return Order::query()->forceCreate(array_replace([
            'order_number' => 'POPULAR-ORDER-'.++$this->orderSequence,
            'status_id' => ($status ?? $this->createStatus('paid', ['is_paid' => true]))->id,
            'source' => 'web', 'currency_code' => 'EUR',
            'customer_name' => 'Popularity fixture', 'customer_email' => 'popularity@example.test',
            'grand_total' => 10, 'placed_at' => now()->subDay(),
        ], $attributes));
    }

    private function item(Order $order, Product $product, int $quantity = 1): void
    {
        $order->items()->create([
            'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->code,
            'quantity' => $quantity, 'unit_price' => 10, 'line_total' => 10 * $quantity,
        ]);
    }
}
