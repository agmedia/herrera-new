<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Services\Import\HerreraLegacyUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HerreraLegacyUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_alias_preserve_chain_and_index_php_all_resolve_directly_to_final_product(): void
    {
        $product = $this->product();
        $this->alias('/stara-kamera', 'product_id=80');
        $this->alias('/jos-starija-kamera', 'product_id=80');
        $this->get('/stara-kamera')->assertRedirect('/product/nova-kamera')->assertStatus(301);
        $this->get('/jos-starija-kamera')->assertRedirect('/product/nova-kamera')->assertStatus(301);
        $this->get('/index.php?route=product/product&product_id=80')->assertRedirect('/product/nova-kamera')->assertStatus(301);
        $this->get('/?route=product/product&product_id=80')->assertRedirect('/product/nova-kamera')->assertStatus(301);
        $this->get('/index.php?route=account/register')->assertRedirect('/auth/b2b-register')->assertStatus(301);
        $this->get('/index.php?route=product/special')->assertRedirect('/akcije')->assertStatus(301);
        $this->get('/index.php?_route_=stara-kamera')->assertRedirect('/product/nova-kamera')->assertStatus(301);
        $this->get('/index.php')->assertRedirect('/')->assertStatus(301);
        $product->update(['is_active' => false]);
        $this->get('/stara-kamera')->assertStatus(410)->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_orphans_and_conflicts_are_explicit_not_homepage_redirects_or_new_404s(): void
    {
        $this->alias('/izbrisano', 'product_id=999', 'unresolved');
        $this->alias('/konflikt', 'product_id=80', 'conflict');
        $this->get('/izbrisano')->assertStatus(410);
        $this->get('/konflikt')->assertStatus(410);
        $this->get('/nepoznata-adresa')->assertNotFound();
        $this->assertNull(HerreraLegacyUrlService::normalizePath('https://evil.test/a'));
        $this->assertNull(HerreraLegacyUrlService::normalizePath('../a'));
        $this->assertNull(HerreraLegacyUrlService::normalizePath('//evil.test'));
    }

    public function test_dynamic_category_prefix_is_accepted_only_when_prefix_is_a_known_category(): void
    {
        $this->product();
        $this->alias('/kamere', 'category_id=10');
        $this->alias('/brend', 'manufacturer_id=9');
        $this->alias('/stara-kamera', 'product_id=80');
        $this->get('/kamere/stara-kamera')->assertStatus(301)->assertRedirect('/product/nova-kamera');
        $this->get('/brend/stara-kamera')->assertStatus(301)->assertRedirect('/product/nova-kamera');
        $this->get('/random/stara-kamera')->assertNotFound();
    }

    private function product(): Product
    {
        $product = Product::create(['code' => 'test-camera', 'sku' => 'TEST-CAM', 'is_active' => true, 'base_price' => 100, 'stock_qty' => 4]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Nova kamera', 'slug' => 'nova-kamera']);
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => 'product', 'source_id' => '80', 'target_id' => $product->id, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);

        return $product;
    }

    private function alias(string $path, string $query, string $status = 'redirect'): void
    {
        DB::table('herrera_legacy_urls')->insert(['path' => $path, 'path_hash' => hash('sha256', $path), 'locale' => 'hr', 'source_query' => $query, 'status' => $status, 'destination' => $status === 'redirect' ? '/product/nova-kamera' : null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
