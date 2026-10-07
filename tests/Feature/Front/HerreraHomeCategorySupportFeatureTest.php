<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Content\ContentBlock;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraHomeCategorySupportFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_card_follows_all_nineteen_categories_with_the_requested_destinations(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $categories = collect(range(1, 19))->map(fn ($index) => $this->category($index));

        $response = $this->get(route('home'))->assertOk()->assertSee('Razgovarajmo o vašoj nabavi.');
        $xpath = $this->xpath($response->getContent());
        $cards = $xpath->query('//*[@data-herrera-category-module]//*[@data-category-card]');
        $this->assertSame($categories->pluck('id')->map(fn ($id) => (string) $id)->all(),
            array_map(fn ($node) => $node->getAttribute('data-category-card'), iterator_to_array($cards)));
        $tiles = $xpath->query('//*[@data-herrera-category-module]/*[contains(@class, "herrera-category-module-grid")]/*');
        $this->assertSame(20, $tiles->count());
        $this->assertTrue($tiles->item(19)->hasAttribute('data-herrera-category-support'));
        $this->assertFalse($tiles->item(19)->hasAttribute('data-category-card'));
        $links = $xpath->query('//*[@data-herrera-category-support]//a');
        $this->assertSame([route('contact.create'), route('front.auth.b2b-register')],
            array_map(fn ($node) => $node->getAttribute('href'), iterator_to_array($links)));
        $this->assertSame(['Kontaktirajte nas', 'Registracija tvrtke'],
            array_map(fn ($node) => trim($node->textContent), iterator_to_array($links)));
        $directory = $this->get(route('categories.index'))->assertOk()->assertSee('data-herrera-category-support', false);
        $directoryXpath = $this->xpath($directory->getContent());
        $directoryCards = $directoryXpath->query('//*[@data-herrera-category-module]//*[@data-category-card]');
        $this->assertSame($categories->pluck('id')->map(fn ($id) => (string) $id)->all(),
            array_map(fn ($node) => $node->getAttribute('data-category-card'), iterator_to_array($directoryCards)));
        $this->assertSame(20, $directoryXpath->query('//*[@data-herrera-category-module]/*[contains(@class, "herrera-category-module-grid")]/*')->count());
    }

    public function test_cms_category_order_is_preserved_and_the_support_card_is_appended_once(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $first = $this->category(1);
        $second = $this->category(2);
        $block = ContentBlock::query()->create(['code' => 'herrera-support-categories', 'name' => 'Support categories',
            'type' => 'featured_categories', 'is_active' => true]);
        $block->translations()->create(['locale' => 'hr', 'title' => 'Support categories']);
        foreach ([$second, $first] as $order => $category) {
            $block->items()->create(['item_type' => 'category', 'item_id' => $category->id, 'sort_order' => $order]);
        }
        $block->slots()->create(['placement' => 'home.categories', 'frontend_variant' => 'desktop', 'is_active' => true]);

        $xpath = $this->xpath($this->get(route('home'))->assertOk()->getContent());
        $cards = $xpath->query('//*[@data-herrera-category-module]//*[@data-category-card]');
        $this->assertSame([(string) $second->id, (string) $first->id],
            array_map(fn ($node) => $node->getAttribute('data-category-card'), iterator_to_array($cards)));
        $this->assertSame(1, $xpath->query('//*[@data-herrera-category-support]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-herrera-category-module]/*[contains(@class, "herrera-category-module-grid")]/*[last()][@data-herrera-category-support]')->count());
    }

    private function category(int $index): Category
    {
        $category = Category::query()->create(['code' => 'support-category-'.$index, 'scope' => Category::SCOPE_CATALOG,
            'is_active' => true, 'sort_order' => $index]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG,
            'name' => 'Support category '.$index, 'slug' => 'support-category-'.$index]);

        return $category;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
