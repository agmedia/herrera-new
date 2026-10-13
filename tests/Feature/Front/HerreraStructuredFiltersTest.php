<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Import\HerreraQuickFixConfigurationService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraStructuredFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_power_and_light_temperature_filters_render_and_combine_in_shop_and_category(): void
    {
        config(['commerce.b2b_only' => true, 'storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->assertTrue(app(HerreraQuickFixConfigurationService::class)->apply());
        $this->assertTrue(app(HerreraQuickFixConfigurationService::class)->applyFilterDefaults());

        $buyer = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'filter-buyer', 'name' => 'Filter buyer', 'is_active' => true]);
        B2BAccount::query()->create([
            'user_id' => $buyer->id, 'company_name' => 'Filter buyer', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
        ]);
        $this->actingAs($buyer);

        $category = Category::query()->create(['code' => 'filter-lights', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => 'Rasvjeta', 'slug' => 'filter-lights']);
        $child = Category::query()->create(['code' => 'filter-child', 'parent_id' => $category->id, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $child->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => 'Reflektori', 'slug' => 'filter-child']);
        $tenWatts = $this->attribute('power-ten', 'herrera-oc-attribute-3', 'Snaga', '10 W');
        $twentyWatts = $this->attribute('power-twenty', 'herrera-oc-attribute-3', 'Snaga', '20 W');
        $warm = $this->attribute('light-warm', 'herrera-oc-attribute-45', 'Temperatura boje', '3000 K');
        $neutral = $this->attribute('light-neutral', 'herrera-oc-attribute-45', 'Temperatura boje', '4000 K');

        $matching = $this->product('ten-neutral', $category, [$tenWatts->id, $neutral->id]);
        $matching->categories()->attach($child, ['is_primary' => false]);
        $tenWarm = $this->product('ten-warm', $category, [$tenWatts->id, $warm->id]);
        $this->product('twenty-neutral', $category, [$twentyWatts->id, $neutral->id]);
        $this->product('twenty-warm', $category, [$twentyWatts->id, $warm->id]);
        $hidden = $this->product('empty-ten-neutral', $category, [$tenWatts->id, $neutral->id]);
        $hidden->update(['stock_qty' => 0]);

        foreach ([route('shop.index'), route('categories.show', ['slug' => 'filter-lights'])] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertSee('name="attr_herrera_oc_attribute_3"', false)
                ->assertSee('name="attr_herrera_oc_attribute_45"', false)
                ->assertSee('Snaga')->assertSee('Temperatura boje')
                ->assertSee('10 W')->assertSee('20 W')->assertSee('3000 K')->assertSee('4000 K');
            $this->assertSame(4, $response->viewData('products')->total());
            $this->assertOnlyCategoryPanelsOpen($response->getContent());

            $powerQuery = ['attr_herrera_oc_attribute_3' => $tenWatts->id];
            $powerProducts = $this->get($url.'?'.http_build_query($powerQuery))->assertOk()->viewData('products');
            $this->assertEqualsCanonicalizing([$matching->id, $tenWarm->id], $powerProducts->pluck('id')->all());

            $selected = $this->get($url.'?'.http_build_query($powerQuery + ['attr_herrera_oc_attribute_45' => $neutral->id]))->assertOk();
            $this->assertSame([$matching->id], $selected->viewData('products')->pluck('id')->all());
            $this->assertSame(1, $selected->viewData('products')->total());
            $filters = collect($selected->viewData('attributeFilters'))->keyBy('query_key');
            $this->assertSame($tenWatts->id, $filters['attr_herrera_oc_attribute_3']['selected']);
            $this->assertSame($neutral->id, $filters['attr_herrera_oc_attribute_45']['selected']);
            $this->assertOnlyCategoryPanelsOpen($selected->getContent());
        }
    }

    private function assertOnlyCategoryPanelsOpen(string $html): void
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        foreach (['catalog-sidebar-section', 'catalog-mobile-filter-section'] as $class) {
            $panels = $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]');
            $this->assertGreaterThanOrEqual(3, $panels->count());
            $openTitles = [];
            foreach ($panels as $panel) {
                if ($panel->hasAttribute('open')) {
                    $openTitles[] = trim($xpath->query('./summary/span[1]', $panel)->item(0)->textContent);
                }
            }
            $this->assertSame([__('ui.shop.filters.category')], $openTitles, 'Only the category panel should open in '.$class.'.');
        }
    }

    private function attribute(string $code, string $groupCode, string $groupName, string $value): Attribute
    {
        $attribute = Attribute::query()->create(['code' => $code, 'group_code' => $groupCode, 'type' => Attribute::TYPE_SELECT, 'is_active' => true]);
        $attribute->translations()->create(['locale' => 'hr', 'group_name' => $groupName, 'name' => $value, 'slug' => $code]);

        return $attribute;
    }

    private function product(string $code, Category $category, array $attributes): Product
    {
        $product = Product::query()->create(['code' => $code, 'sku' => strtoupper($code), 'is_active' => true, 'base_price' => 10, 'stock_qty' => 5]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Reflektor '.$code, 'slug' => $code]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $product->attributes()->attach($attributes);

        return $product;
    }
}
