<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Content\ContentBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class HomePopularBrandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_home_renders_selected_popular_brands_below_products(): void
    {
        $bosch = $this->createManufacturer('bosch', 'Bosch');
        $customBrand = $this->createManufacturer('custom-brand', 'Custom Brand');
        $inactiveBrand = $this->createManufacturer('inactive-brand', 'Inactive Brand', false);

        $logo = UploadedFile::fake()->image('custom-brand.png', 600, 240);
        $customBrand
            ->addMedia($logo->getPathname())
            ->usingName('Custom Brand')
            ->usingFileName('custom-brand.png')
            ->toMediaCollection('manufacturer_logo');

        $productsBlock = ContentBlock::query()->create([
            'code' => 'home-products-before-brands-test',
            'name' => 'Blok proizvoda',
            'type' => 'rich_text',
            'is_active' => true,
        ]);
        $productsBlock->translations()->create([
            'locale' => 'hr',
            'title' => 'Blok proizvoda',
        ]);
        $productsBlock->slots()->create([
            'placement' => 'home.before_products',
            'frontend_variant' => 'desktop',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $brandsBlock = ContentBlock::query()->create([
            'code' => 'home-popular-brands-test',
            'name' => 'Popularni brendovi',
            'type' => 'popular_brands',
            'is_active' => true,
        ]);
        $brandsBlock->translations()->create([
            'locale' => 'hr',
            'title' => 'Popularni brendovi',
            'cta_label' => 'Svi brendovi',
        ]);
        foreach ([$bosch, $customBrand, $inactiveBrand] as $index => $manufacturer) {
            $brandsBlock->items()->create([
                'item_type' => 'manufacturer',
                'item_id' => $manufacturer->id,
                'sort_order' => $index,
            ]);
        }
        $brandsBlock->slots()->create([
            'placement' => 'home.after_products',
            'frontend_variant' => 'desktop',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0 Safari/537.36',
            ])
            ->get('/')
            ->assertOk()
            ->assertSee('data-popular-brands', false)
            ->assertSee('data-popular-brand="'.$bosch->id.'"', false)
            ->assertSee('data-popular-brand="'.$customBrand->id.'"', false)
            ->assertDontSee('data-popular-brand="'.$inactiveBrand->id.'"', false)
            ->assertSee('Popularni brendovi')
            ->assertSee('https://cdn.simpleicons.org/bosch', false)
            ->assertSee('alt="Logotip brenda Custom Brand"', false)
            ->assertSee('/manufacturer/bosch', false)
            ->assertSee('href="'.route('manufacturers.index').'"', false)
            ->assertSee('Svi brendovi')
            ->assertSeeInOrder([
                'class="popular-brands-heading storefront-widget-heading--split"',
                'class="storefront-widget-heading-title"',
                'Svi brendovi',
                'class="popular-brands-grid"',
            ], false)
            ->assertDontSee('popular-brands-cta', false)
            ->assertSeeInOrder(['Blok proizvoda', 'Popularni brendovi']);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString(
            '.popular-brands-grid {'."\n".'        grid-template-columns: repeat(6, minmax(0, 1fr));',
            $css
        );
    }

    private function createManufacturer(
        string $code,
        string $name,
        bool $isActive = true
    ): Manufacturer {
        $manufacturer = Manufacturer::query()->create([
            'code' => $code,
            'is_active' => $isActive,
            'is_featured' => true,
            'sort_order' => 0,
        ]);
        $manufacturer->translations()->create([
            'locale' => 'hr',
            'name' => $name,
            'slug' => $code,
        ]);

        return $manufacturer;
    }
}
