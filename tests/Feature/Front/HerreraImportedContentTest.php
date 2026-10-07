<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraImportedContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_blog_category_filters_posts_and_preserves_its_canonical_and_live_cover(): void
    {
        config(['app.locale' => 'hr', 'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://herrera.hr']);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->put('catalog_use_blog', true);
        $category = Category::create(['scope' => 'blog', 'code' => 'blog-news', 'is_active' => true]);
        $category->translations()->create(['scope' => 'blog', 'locale' => 'hr', 'name' => 'Novosti', 'slug' => 'novosti', 'meta_title' => 'Herrera novosti']);
        $selected = $this->blogPost('Odabrana vijest', 'odabrana-vijest');
        $selected->categories()->attach($category);
        $this->blogPost('Druga vijest', 'druga-vijest');
        $this->get('/blog?category=novosti')->assertOk()->assertSee('Odabrana vijest')->assertDontSee('Druga vijest')
            ->assertSee('https://herrera.hr/image/catalog/news.jpg', false)
            ->assertSee('href="'.route('blog.index', ['category' => 'novosti']).'"', false);
        $this->get('/blog/odabrana-vijest')->assertOk()->assertSee('https://herrera.hr/image/catalog/news.jpg', false);
    }

    public function test_explicit_imported_related_products_are_first_and_inactive_products_are_never_shown(): void
    {
        config(['app.locale' => 'hr']);
        app()->setLocale('hr');
        $related = $this->product('Izvorni povezani proizvod', 'related');
        $inactive = $this->product('Neaktivan proizvod', 'inactive', false);
        $main = $this->product('Glavni proizvod', 'main');
        $main->update(['payload' => ['related_product_ids' => [$inactive->id, $related->id]]]);
        $this->product('Noviji prijedlog', 'newer');
        $response = $this->get('/product/main')->assertOk();
        $response->assertViewHas('related', fn ($products) => $products->first()->id === $related->id && ! $products->contains('id', $inactive->id));
    }

    private function blogPost(string $name, string $slug): BlogPost
    {
        $post = BlogPost::create(['code' => $slug, 'is_active' => true, 'payload' => ['opencart' => ['image' => 'catalog/news.jpg']]]);
        $post->translations()->create(['locale' => 'hr', 'title' => $name, 'slug' => $slug, 'body_html' => '<p>Vijest</p>']);

        return $post;
    }

    private function product(string $name, string $slug, bool $active = true): Product
    {
        $product = Product::create(['code' => $slug, 'sku' => $slug, 'is_active' => $active, 'base_price' => 50, 'stock_qty' => 4]);
        $product->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => $slug]);

        return $product;
    }
}
