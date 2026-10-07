<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManufacturerCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'catalog_use_manufacturers' => true,
            'catalog_hide_out_of_stock_products' => false,
        ]);
    }

    #[DataProvider('devices')]
    public function test_category_navigation_keeps_the_brand_through_children_current_category_and_breadcrumbs(string $userAgent): void
    {
        $brand = $this->manufacturer('selected-brand');
        $otherBrand = $this->manufacturer('other-brand');
        $root = $this->category('brand-lighting');
        $child = $this->category('brand-led', $root);
        $leaf = $this->category('brand-bulbs', $child);
        $otherChild = $this->category('other-brand-lights', $root);
        $otherRoot = $this->category('brand-cables');
        $unrelatedRoot = $this->category('other-brand-only');
        $light = $this->product('selected-brand-light', $leaf, $brand);
        $light->categories()->attach($root);
        $cable = $this->product('selected-brand-cable', $otherRoot, $brand);
        $this->product('other-brand-light', $leaf, $otherBrand);
        $this->product('other-brand-sibling-light', $otherChild, $otherBrand);
        $this->product('other-brand-cable', $otherRoot, $otherBrand);
        $this->product('other-brand-unrelated', $unrelatedRoot, $otherBrand);

        $brandUrl = route('manufacturers.show', ['slug' => 'selected-brand']);
        $response = $this->withHeaders(['User-Agent' => $userAgent])->get($brandUrl)->assertOk();
        $this->assertProducts($response, [$light->id, $cable->id]);
        $this->assertSame([$root->id, $otherRoot->id], $response->viewData('categories')->pluck('id')->all());
        $this->assertSame(1, (int) $response->viewData('categories')->first()->products_count);
        $rootUrl = $this->categoryUrl('brand-lighting');
        $rootLink = $this->assertCategoryLink($response, $rootUrl);
        $this->assertCurrentCategoryLink($response, $brandUrl);

        $response = $this->get($rootLink)->assertOk();
        $this->assertProducts($response, [$light->id]);
        $this->assertSame('selected-brand', $response->viewData('filters')['manufacturer']);
        $this->assertSame(1, (int) $response->viewData('subcategories')->firstWhere('id', $child->id)->products_count);
        $this->assertSame(0, (int) ($response->viewData('subcategories')->firstWhere('id', $otherChild->id)?->products_count ?? 0));
        $childUrl = $this->categoryUrl('brand-led');
        $childLink = $this->assertCategoryLink($response, $childUrl);
        $currentLink = $this->assertCurrentCategoryLink($response, $rootUrl);
        $this->assertProducts($this->get($currentLink)->assertOk(), [$light->id]);

        $response = $this->get($childLink)->assertOk();
        $this->assertProducts($response, [$light->id]);
        $this->assertSame([$leaf->id], $response->viewData('subcategories')->pluck('id')->all());
        $this->assertSame(1, (int) $response->viewData('subcategories')->first()->products_count);
        $leafUrl = $this->categoryUrl('brand-bulbs');
        $leafLink = $this->assertCategoryLink($response, $leafUrl);
        $this->assertCurrentCategoryLink($response, $childUrl);

        $response = $this->get($leafLink)->assertOk();
        $this->assertProducts($response, [$light->id]);
        $breadcrumbLinks = $this->xpath($response)->query('//nav[@aria-label="Breadcrumb"]//a');
        $hrefs = [];
        foreach ($breadcrumbLinks as $link) {
            $hrefs[] = $link->getAttribute('href');
        }
        $this->assertContains($rootUrl, $hrefs);
        $this->assertContains($childUrl, $hrefs);
        foreach ([$rootUrl, $childUrl] as $ancestorUrl) {
            $this->assertProducts($this->get($ancestorUrl)->assertOk(), [$light->id]);
        }
    }

    public static function devices(): array
    {
        return [
            'desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/122.0.0.0 Safari/537.36'],
            'mobile' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1'],
        ];
    }

    private function assertProducts(TestResponse $response, array $expectedIds): void
    {
        $this->assertSame(count($expectedIds), $response->viewData('products')->total());
        $this->assertEqualsCanonicalizing($expectedIds, $response->viewData('products')->pluck('id')->all());
    }

    private function assertCategoryLink(TestResponse $response, string $expectedUrl): string
    {
        $xpath = $this->xpath($response);
        foreach (['catalog-sidebar-category-options', 'catalog-mobile-filter-options'] as $class) {
            $links = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]/a[not(@aria-current)]');
            $hrefs = [];
            foreach ($links as $link) {
                $hrefs[] = $link->getAttribute('href');
            }
            $this->assertContains($expectedUrl, $hrefs, 'Category links must keep the selected manufacturer in '.$class.'.');
        }

        return $hrefs[array_search($expectedUrl, $hrefs, true)];
    }

    private function assertCurrentCategoryLink(TestResponse $response, string $expectedUrl): string
    {
        $xpath = $this->xpath($response);
        foreach (['catalog-sidebar-category-options', 'catalog-mobile-filter-options'] as $class) {
            $links = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]/a[@aria-current="page"]');
            $this->assertSame(1, $links->count());
            $this->assertSame($expectedUrl, $links->item(0)->getAttribute('href'));
        }

        return $links->item(0)->getAttribute('href');
    }

    private function categoryUrl(string $slug): string
    {
        return route('categories.show', ['slug' => $slug, 'manufacturer' => 'selected-brand']);
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = Category::query()->create(['code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true, 'parent_id' => $parent?->id]);
        $category->translations()->create(['scope' => Category::SCOPE_CATALOG, 'locale' => 'hr', 'name' => $slug, 'slug' => $slug]);

        return $category;
    }

    private function product(string $code, Category $category, Manufacturer $manufacturer): Product
    {
        $product = Product::query()->create(['code' => $code, 'base_price' => 100, 'stock_qty' => 10, 'manufacturer_id' => $manufacturer->id, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        $product->categories()->attach($category);

        return $product;
    }

    private function manufacturer(string $slug): Manufacturer
    {
        $manufacturer = Manufacturer::query()->create(['code' => $slug, 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $slug, 'slug' => $slug]);

        return $manufacturer;
    }
}
