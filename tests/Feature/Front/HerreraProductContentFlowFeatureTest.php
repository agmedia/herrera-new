<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraProductContentFlowFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_herrera_shows_sequential_sections_without_tabs_and_preserves_content_and_review_form(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->product();

        $this->get(route('products.show', ['slug' => 'content-flow-product']))->assertOk()
            ->assertDontSee('data-product-detail-tabs', false)
            ->assertDontSee('data-product-detail-tab', false)
            ->assertSee('front-theme/styles/herrera-product-tabs.css', false)
            ->assertSeeInOrder(['id="product-description"', 'id="product-specifications"', 'id="product-comments"'], false)
            ->assertSee('Opis iz urednika proizvoda.')
            ->assertSee('Napon')->assertSee('230 V')
            ->assertSee('Provjeren poslovni kupac')->assertSee('Pouzdan za našu nabavu.')
            ->assertSee('data-comment-form-toggle', false)->assertSee('data-comment-form-panel', false)
            ->assertSee('action="'.route('products.comments.store', ['slug' => 'content-flow-product']).'"', false)
            ->assertSee('id="product-comment-body"', false);
    }

    public function test_other_stores_keep_the_existing_detail_navigation(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Termol');
        $this->product();

        $this->get(route('products.show', ['slug' => 'content-flow-product']))->assertOk()
            ->assertSee('data-product-detail-tabs', false)
            ->assertSee('href="#product-description"', false)
            ->assertSee('href="#product-specifications"', false)
            ->assertSee('href="#product-comments"', false)
            ->assertDontSee('front-theme/styles/herrera-product-tabs.css', false);
    }

    private function product(): Product
    {
        $product = Product::query()->create([
            'code' => 'CONTENT-FLOW', 'is_active' => true, 'base_price' => 20, 'stock_qty' => 1,
        ]);
        $product->translations()->create([
            'locale' => 'hr', 'name' => 'Content flow product', 'slug' => 'content-flow-product',
            'description' => '<p>Opis iz urednika proizvoda.</p>',
        ]);
        $product->technicalSpecificationRows()->create([
            'source' => 'opencart', 'source_key' => 'voltage', 'group_name' => 'Električne karakteristike',
            'item_name' => 'Napon', 'values' => ['230'], 'measure' => 'V',
        ]);
        Comment::query()->create([
            'commentable_type' => Product::class, 'commentable_id' => $product->id,
            'author_name' => 'Provjeren poslovni kupac', 'author_email' => 'buyer@example.test',
            'locale' => 'hr', 'body' => 'Pouzdan za našu nabavu.', 'rating' => 5,
            'status' => Comment::STATUS_APPROVED,
        ]);

        return $product;
    }
}
