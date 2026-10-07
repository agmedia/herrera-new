<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HerreraHomeProductDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_cards_batch_load_reviews_materials_and_options(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        app()->setLocale('hr');
        config(['app.locale' => 'hr', 'storefront_cache.enabled' => false]);

        foreach ([5165, 6851, 32121] as $code) {
            $product = Product::query()->create([
                'code' => 'herrera-oc-product-'.$code,
                'is_active' => true,
                'stock_qty' => 5,
                'base_price' => 10,
            ]);
            $product->translations()->create([
                'locale' => 'hr', 'name' => 'Odabrani proizvod '.$code, 'slug' => 'odabrani-proizvod-'.$code,
            ]);
            $product->comments()->create([
                'author_name' => 'Test', 'locale' => 'hr', 'body' => 'Odobrena recenzija',
                'rating' => 5, 'status' => Comment::STATUS_APPROVED,
            ]);
            $product->comments()->create([
                'author_name' => 'Test', 'locale' => 'hr', 'body' => 'Recenzija na čekanju',
                'rating' => 1, 'status' => Comment::STATUS_PENDING,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get(route('home'))->assertOk()
            ->assertSee('Odabrani proizvod 5165')
            ->assertSee('/product/odabrani-proizvod-5165#product-comments', false);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertStringNotContainsString('Recenzija na čekanju', $response->getContent());
        $this->assertSame(0, $queries->filter(fn ($sql) => str_contains($sql, 'COUNT(*) as review_count'))->count());
        $this->assertSame(1, $queries->filter(fn ($sql) => str_contains($sql, 'from "catalog_attributes" inner join "catalog_attribute_product"'))->count());
        $this->assertSame(1, $queries->filter(fn ($sql) => str_contains($sql, 'from "catalog_product_option_values"'))->count());
    }
}
