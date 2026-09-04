<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\CatalogProductSpecification;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Services\Front\CartService;
use App\Support\ProductEnergyLabelPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductEnergyPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_cart_and_mini_cart_show_energy_label_without_render_time_api_calls(): void
    {
        $product = $this->product();
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'manual-main',
            'label' => 'Glavna energetska oznaka',
            'energy_class' => 'C',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'energy_label_url' => 'https://cdn.example.test/labels/main.pdf',
            'product_information_sheet_url' => 'https://cdn.example.test/fiches/main.pdf',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'manual-secondary',
            'label' => 'Dodatni kontekst',
            'energy_class' => 'D',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'energy_label_url' => 'https://cdn.example.test/labels/secondary.pdf',
            'product_information_sheet_url' => 'https://cdn.example.test/fiches/secondary.pdf',
            'is_primary' => false,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);
        CatalogProductSpecification::query()->create([
            'product_id' => $product->id,
            'source' => 'msan',
            'source_key' => hash('sha256', 'dangerous-spec'),
            'group_name' => 'Učinkovitost',
            'item_name' => 'Deklarirana vrijednost',
            'values' => ['<script>alert(1)</script>', '42'],
            'measure' => 'W',
            'sort_order' => 1,
        ]);

        $this->get(route('products.show', ['slug' => 'energy-front-product']))
            ->assertOk()
            ->assertSee('data-energy-label-arrow', false)
            ->assertSee('https://cdn.example.test/labels/main.pdf', false)
            ->assertSee('Informacijski list proizvoda (PIS)')
            ->assertSee('Dodatni kontekst')
            ->assertSee('data-product-technical-specifications', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('data-energy-label-arrow', false)
            ->assertSee('data-product-information-sheet', false);

        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product));
        $line = $cart->lines()->first();
        $this->assertSame('C', $line['energy_declaration']['energy_class']);

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('data-energy-label-arrow', false)
            ->assertSee('data-product-information-sheet', false)
            ->assertSee('https://cdn.example.test/labels/main.pdf', false);

        $this->get(route('cart.preview'))
            ->assertOk()
            ->assertSee('data-energy-label-arrow', false)
            ->assertSee('data-product-information-sheet', false);

        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('data-energy-label-arrow', false)
            ->assertSee('data-product-information-sheet', false);
    }

    public function test_incomplete_energy_data_does_not_render_a_price_arrow(): void
    {
        $product = $this->product('energy-incomplete-product');
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'incomplete',
            'energy_class' => 'A',
            'scale_min' => 'A',
            'scale_max' => null,
            'energy_label_url' => 'https://cdn.example.test/labels/incomplete.pdf',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);

        $this->get(route('products.show', ['slug' => 'energy-incomplete-product']))
            ->assertOk()
            ->assertDontSee('data-energy-label-arrow', false);
    }

    public function test_thumbnail_only_energy_arrow_is_visible_but_never_used_as_the_full_label_link(): void
    {
        $product = $this->product('energy-thumbnail-only');
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'thumbnail-only',
            'energy_class' => 'C',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'energy_label_image' => 'C A-G.svg',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
        ]);
        $thumbnailUrl = 'https://ec.europa.eu/assets/move-ener/eprel/EPREL%20Public/Nested-labels%20thumbnails/C%20A-G.svg';

        $this->get(route('products.show', ['slug' => 'energy-thumbnail-only']))
            ->assertOk()
            ->assertSee('data-energy-label-arrow', false)
            ->assertSee($thumbnailUrl, false)
            ->assertDontSee('href="'.$thumbnailUrl.'"', false);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('data-energy-label-arrow', false)
            ->assertSee($thumbnailUrl, false)
            ->assertDontSee('href="'.$thumbnailUrl.'"', false);
    }

    public function test_local_admin_assets_are_used_without_remote_or_render_time_queries(): void
    {
        Storage::fake('public');

        $product = $this->product('energy-local-assets');
        $product->addMedia(UploadedFile::fake()->image('energy-label.png', 200, 300))
            ->toMediaCollection('product_energy_label', 'public');
        $product->addMedia(UploadedFile::fake()->createWithContent(
            'information-sheet.pdf',
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",
        ))->toMediaCollection('product_information_sheet', 'public');
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'manual-local',
            'energy_class' => 'B',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);

        DB::enableQueryLog();
        $loaded = Product::query()->withStorefrontEnergyData()->findOrFail($product->id);
        $queryCountAfterLoad = count(DB::getQueryLog());

        $declaration = app(ProductEnergyLabelPresenter::class)->primary($loaded);

        $this->assertTrue($loaded->relationLoaded('energyDeclarations'));
        $this->assertTrue($loaded->relationLoaded('energyMedia'));
        $this->assertNotNull($declaration);
        $this->assertStringContainsString('/storage/', $declaration['energy_label_url']);
        $this->assertStringContainsString('/storage/', $declaration['product_information_sheet_url']);
        $this->assertSame($queryCountAfterLoad, count(DB::getQueryLog()));
    }

    private function product(string $slug = 'energy-front-product'): Product
    {
        $product = Product::query()->create([
            'code' => strtoupper($slug),
            'sku' => strtoupper($slug),
            'is_active' => true,
            'base_price' => 99,
            'stock_qty' => 4,
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => 'Proizvod s energetskom oznakom',
            'slug' => $slug,
            'description' => '<p>Opis proizvoda.</p>',
        ]);

        return $product;
    }
}
