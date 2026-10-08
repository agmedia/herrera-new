<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraMobileProductGridFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'store_product_mobile_default_cols' => 2,
            'store_product_desktop_default_cols' => 5,
        ]);
        $category = Category::query()->create(['code' => 'mobile-grid', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => 'Mobile grid', 'slug' => 'mobile-grid']);
        foreach ([1, 2] as $index) {
            $product = Product::query()->create(['code' => 'MOBILE-GRID-'.$index, 'is_active' => true, 'base_price' => 24.99, 'stock_qty' => 5]);
            $product->translations()->create(['locale' => 'hr', 'name' => 'Mobile grid product '.$index, 'slug' => 'mobile-grid-product-'.$index]);
            $product->categories()->attach($category, ['is_primary' => true]);
        }
    }

    public function test_mobile_grid_defaults_to_two_and_both_switch_links_select_the_requested_columns(): void
    {
        $xpath = $this->xpath($this->get('/category/mobile-grid')->assertOk()->getContent());
        $this->assertSame('2', $xpath->query('//*[@data-catalog-grid]')->item(0)->getAttribute('data-catalog-mobile-cols'));

        foreach ([1, 2] as $columns) {
            $toggle = $xpath->query('//a[contains(@class, "catalog-mobile-grid-toggle") and @data-catalog-grid-cols="'.$columns.'"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $toggle);
            $response = $this->get($toggle->getAttribute('href'))->assertOk()->assertCookie('front_grid_cols', (string) $columns);
            $selected = $this->xpath($response->getContent());
            $grid = $selected->query('//*[@data-catalog-grid]')->item(0);
            $this->assertSame((string) $columns, $grid->getAttribute('data-catalog-mobile-cols'));
            $this->assertSame(2, $selected->query('//*[@data-catalog-grid]/*[@data-product-card]')->count());
            $this->assertSame(1, $selected->query('//a[contains(@class, "catalog-mobile-grid-toggle") and @data-catalog-grid-cols="'.$columns.'" and @aria-current="true"]')->count());
        }
    }

    public function test_saved_mobile_preference_survives_navigation_without_reducing_desktop_columns(): void
    {
        foreach ([1, 2] as $columns) {
            $xpath = $this->xpath($this->withCookie('front_grid_cols', (string) $columns)->get('/category/mobile-grid')->assertOk()->getContent());
            $grid = $xpath->query('//*[@data-catalog-grid]')->item(0);
            $this->assertSame((string) $columns, $grid->getAttribute('data-catalog-mobile-cols'));
            $this->assertStringContainsString('2xl:grid-cols-5', $grid->getAttribute('class'));
        }

        $xpath = $this->xpath($this->withCookie('front_grid_cols', '1')->get('/category/mobile-grid?cols=2')->assertOk()->getContent());
        $this->assertSame('2', $xpath->query('//*[@data-catalog-grid]')->item(0)->getAttribute('data-catalog-mobile-cols'));
    }

    public function test_approved_b2b_card_keeps_energy_badge_and_sheet_in_one_group_beside_the_price_and_purchase_controls(): void
    {
        $group = CustomerGroup::query()->create(['code' => 'mobile-grid', 'name' => 'Mobile grid', 'is_active' => true]);
        $user = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $user->id, 'status' => B2BAccount::STATUS_APPROVED, 'company_name' => 'Mobile grid', 'oib' => '12345678901', 'country_code' => 'HR', 'customer_group_id' => $group->id]);
        ProductEnergyDeclaration::query()->create([
            'product_id' => Product::query()->first()->id, 'context_code' => 'main', 'energy_class' => 'C',
            'scale_min' => 'A', 'scale_max' => 'G', 'energy_label_url' => 'https://example.test/energy.pdf',
            'product_information_sheet_url' => 'https://example.test/sheet.pdf', 'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);
        $xpath = $this->xpath($this->actingAs($user)->get('/category/mobile-grid?cols=2')->assertOk()->getContent());
        $documents = $xpath->query('//*[@data-catalog-grid]//*[contains(@class, "product-card-energy-documents") and .//*[@data-energy-label-arrow] and .//*[@data-product-information-sheet]]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $documents);
        $this->assertSame(1, $xpath->query('../p[contains(@class, "product-card-current-price")]', $documents)->count());
        $this->assertSame(2, $xpath->query('//*[@data-catalog-grid]//*[@data-product-card-form]//*[@data-qty-input]')->count());
        $this->assertSame(2, $xpath->query('//*[@data-catalog-grid]//*[@data-product-card-form]//button[@type="submit"]')->count());
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }
}
