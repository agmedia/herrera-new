<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Import\HerreraBlogRelatedProductsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraBlogRelatedProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'storefront_cache.enabled' => false, 'app.locale' => 'hr']);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera', 'catalog_use_blog' => true,
            'catalog_hide_out_of_stock_products' => true,
        ]);
    }

    public function test_curated_product_codes_populate_each_existing_article_once_and_preserve_later_edits(): void
    {
        $configuration = config('herrera-blog-related-products');
        $products = collect($configuration)->flatten()->unique()->mapWithKeys(fn (string $code): array => [$code => $this->product($code)]);
        $posts = collect(array_keys($configuration))->map(fn (string $code): BlogPost => $this->article($code));
        $service = app(HerreraBlogRelatedProductsService::class);
        $this->assertSame(3, $service->apply());
        foreach ($posts as $post) {
            $expected = array_map(fn (string $code): int => $products[$code]->id, $configuration[$post->code]);
            $this->assertSame($expected, $post->fresh()->payload['related_product_ids']);
            $this->assertSame('keep', $post->fresh()->payload['original']);
            $this->assertSame('Original article', $post->translations()->where('locale', 'hr')->sole()->title);
            $this->get(route('blog.show', ['slug' => $post->code]))->assertOk()
                ->assertViewHas('relatedProducts', fn ($related): bool => $related->pluck('id')->all() === $expected)
                ->assertSee('data-blog-related-products', false);
        }

        $first = $posts->first()->fresh();
        $first->update(['payload' => array_replace($first->payload, ['related_product_ids' => []])]);
        $second = $posts->get(1)->fresh();
        $reordered = array_reverse($second->payload['related_product_ids']);
        $second->update(['payload' => array_replace($second->payload, ['related_product_ids' => $reordered])]);
        $this->assertSame(0, $service->apply());
        $this->assertSame([], $first->fresh()->payload['related_product_ids']);
        $this->assertSame($reordered, $second->fresh()->payload['related_product_ids']);
    }

    public function test_existing_manual_links_and_later_manual_clear_are_preserved(): void
    {
        $product = $this->product('manual-product');
        $post = $this->article('herrera-oc-blog-10368', ['related_product_ids' => [$product->id]]);
        $service = app(HerreraBlogRelatedProductsService::class);
        $this->assertSame(0, $service->apply());
        $post->refresh();
        $this->assertSame([$product->id], $post->payload['related_product_ids']);
        $this->assertSame('preserved_manual', $post->payload[HerreraBlogRelatedProductsService::PAYLOAD_VERSION_KEY]['mode']);
        $post->update(['payload' => array_replace($post->payload, ['related_product_ids' => []])]);
        $this->assertSame(0, $service->apply());
        $this->assertSame([], $post->fresh()->payload['related_product_ids']);
    }

    public function test_missing_article_or_incomplete_product_import_remains_pending(): void
    {
        config(['herrera-blog-related-products' => ['pending-article' => ['needed-one', 'needed-two']]]);
        $service = app(HerreraBlogRelatedProductsService::class);
        $this->assertSame(0, $service->apply());
        $post = $this->article('pending-article');
        $one = $this->product('needed-one');
        $this->assertSame(0, $service->apply());
        $this->assertArrayNotHasKey('related_product_ids', $post->fresh()->payload);
        $this->assertArrayNotHasKey(HerreraBlogRelatedProductsService::PAYLOAD_VERSION_KEY, $post->fresh()->payload);
        $two = $this->product('needed-two');
        $this->assertSame(1, $service->apply());
        $this->assertSame([$one->id, $two->id], $post->fresh()->payload['related_product_ids']);
    }

    #[DataProvider('viewerStates')]
    public function test_related_cards_preserve_manual_order_and_catalog_visibility_without_disclosing_b2b_prices(string $state): void
    {
        if ($state !== 'guest') {
            $this->actingAs($this->buyer($state));
        }
        $local = $this->product('related-local');
        $supplier = $this->product('related-supplier', ['stock_qty' => 0, 'supplier_stock_qty' => 17]);
        $empty = $this->product('related-empty', ['stock_qty' => 0]);
        $inactive = $this->product('related-inactive', ['is_active' => false]);
        $noSlug = $this->product('related-no-slug');
        $noSlug->translations()->update(['slug' => '']);
        $variant = $this->product('related-variant', ['stock_qty' => 0]);
        $option = Option::query()->create(['code' => 'blog-variant', 'type' => 'select', 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'blog-variant-value', 'is_active' => true]);
        $variant->optionValues()->create([
            'option_value_id' => $value->id, 'sku' => 'RELATED-VARIANT', 'stock_qty' => 3,
            'is_active' => true, 'combination_hash' => hash('sha256', 'related-variant'),
        ]);
        $post = $this->article('manual-related-article', ['related_product_ids' => [
            $supplier->id, $inactive->id, $local->id, $empty->id, $variant->id, $noSlug->id, $local->id, -1,
        ]]);

        $response = $this->get(route('blog.show', ['slug' => $post->code]))->assertOk()
            ->assertSee('data-blog-related-products', false)
            ->assertViewHas('relatedProducts', fn ($products): bool => $products->pluck('id')->all() === [$supplier->id, $local->id, $variant->id])
            ->assertViewHas('relatedProducts', fn ($products): bool => $products->every(fn (Product $product): bool => $product->relationLoaded('taxRate')
                && $product->relationLoaded('energyDeclarations') && $product->relationLoaded('media')
                && $product->relationLoaded('optionValues') && $product->relationLoaded('attributes')));

        if ($state === 'approved') {
            $response->assertSee('data-product-card-form', false)->assertSee('data-product-local-stock', false)
                ->assertSee('product-card-current-price', false);
        } else {
            $response->assertDontSee('data-product-card-form', false)->assertDontSee('data-product-local-stock', false)
                ->assertDontSee('data-product-supplier-stock', false)->assertDontSee('product-card-current-price', false)
                ->assertDontSee('27.45')->assertDontSee('27,45');
        }

        // A linked product can reappear after stock synchronization without editing the article.
        $empty->update(['supplier_stock_qty' => 9]);
        $this->get(route('blog.show', ['slug' => $post->code]))->assertOk()
            ->assertViewHas('relatedProducts', fn ($products): bool => $products->pluck('id')->all() === [$supplier->id, $local->id, $empty->id, $variant->id]);
    }

    public static function viewerStates(): array
    {
        return [['guest'], ['pending'], ['approved']];
    }

    public function test_setup_rejects_another_store_without_altering_article_links(): void
    {
        $post = $this->article('herrera-oc-blog-10368');
        app(SystemSettingsService::class)->put('store_brand_name', 'Other store');
        try {
            app(HerreraBlogRelatedProductsService::class)->apply();
            $this->fail('Another store must not receive Herrera product assignments.');
        } catch (\LogicException) {
            $this->assertSame(['original' => 'keep'], $post->fresh()->payload);
        }
    }

    private function article(string $code, array $payload = []): BlogPost
    {
        $post = BlogPost::query()->create(['code' => $code, 'is_active' => true, 'published_at' => now()->subDay(), 'payload' => ['original' => 'keep'] + $payload]);
        $post->translations()->create(['locale' => 'hr', 'title' => 'Original article', 'slug' => $code, 'body_html' => '<p>Original body.</p>']);

        return $post;
    }

    private function product(string $code, array $attributes = []): Product
    {
        $product = Product::query()->create(array_replace(['code' => $code, 'sku' => strtoupper($code), 'is_active' => true, 'base_price' => 27.45, 'stock_qty' => 5], $attributes));
        $product->translations()->create(['locale' => 'hr', 'name' => 'Fixture '.$code, 'slug' => $code]);

        return $product;
    }

    private function buyer(string $status): User
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'blog-buyer', 'name' => 'Blog buyer', 'is_active' => true]);
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Test d.o.o.', 'oib' => '12345678901', 'status' => $status, 'customer_group_id' => $group->id]);

        return $user;
    }
}
